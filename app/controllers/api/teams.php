<?php

use Appwrite\Auth\Auth;
use Appwrite\Detector\Detector;
use Appwrite\Event\Audit as EventAudit;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Mail;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\Email;
use Appwrite\Network\Validator\Host;
use Appwrite\Template\Template;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\DateTime;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\ArrayList;
use Utopia\Validator\WhiteList;

App::post('/v1/teams')
    ->desc('Create Team')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].create')
    ->label('scope', 'teams.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/teams/create-team.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TEAM)
    ->param('teamId', '', new CustomId(), 'Team ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', null, new Text(128), 'Team name. Max length: 128 chars.')
    ->param('roles', ['owner'], new ArrayList(new Key(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of strings. Use this param to set the roles in the team for the user who created it. The default role is **owner**. A role can be any string. Learn more about [roles and permissions](/docs/permissions). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 32 characters long.', true)
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('audits')
    ->action(function (string $teamId, string $name, array $roles, Response $response, Document $user, Database $dbForProject, Event $events, Event $audits) {

        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());
        $isAppUser = Auth::isAppUser(Authorization::getRoles());

        $teamId = $teamId == 'unique()' ? $dbForProject->getId() : $teamId;
        $team = Authorization::skip(fn() => $dbForProject->createDocument('teams', new Document([
            '$id' => $teamId ,
            '$read' => ['team:' . $teamId],
            '$write' => ['team:' . $teamId . '/owner'],
            'name' => $name,
            'total' => ($isPrivilegedUser || $isAppUser) ? 0 : 1,
            'search' => implode(' ', [$teamId, $name]),
        ])));

        if (!$isPrivilegedUser && !$isAppUser) { // Don't add user on server mode
            $membershipId = $dbForProject->getId();
            $membership = new Document([
                '$id' => $membershipId,
                '$read' => ['user:' . $user->getId(), 'team:' . $team->getId()],
                '$write' => ['user:' . $user->getId(), 'team:' . $team->getId() . '/owner'],
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'teamId' => $team->getId(),
                'teamInternalId' => $team->getInternalId(),
                'roles' => $roles,
                'invited' => DateTime::now(),
                'joined' => DateTime::now(),
                'confirm' => true,
                'secret' => '',
                'search' => implode(' ', [$membershipId, $user->getId()])
            ]);

            $membership = $dbForProject->createDocument('memberships', $membership);
            $dbForProject->deleteCachedDocument('users', $user->getId());
        }

        $events->setParam('teamId', $team->getId());

        if (!empty($user->getId())) {
            $events->setParam('userId', $user->getId());
        }

        $audits
            ->setParam('event', 'teams.create')
            ->setParam('resource', 'team/' . $teamId)
            ->setParam('data', $team->getArrayCopy())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($team, Response::MODEL_TEAM);
    });

App::get('/v1/teams')
    ->desc('List Teams')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/teams/list-teams.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TEAM_LIST)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of teams to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this param to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the team used as the starting point for the query, excluding the team itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor, can be either \'before\' or \'after\'.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $search, int $limit, int $offset, string $cursor, string $cursorDirection, string $orderType, Response $response, Database $dbForProject) {

        $filterQueries = [];

        if (!empty($search)) {
            $filterQueries[] = Query::search('search', $search);
        }

        $queries = [];
        $queries[] = Query::limit($limit);
        $queries[] = Query::offset($offset);
        $queries[] = $orderType === Database::ORDER_ASC ? Query::orderAsc('') : Query::orderDesc('');
        if (!empty($cursor)) {
            $cursorDocument = $dbForProject->getDocument('teams', $cursor);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Team '{$cursor}' for the 'cursor' value not found.");
            }

            $queries[] = $cursorDirection === Database::CURSOR_AFTER ? Query::cursorAfter($cursorDocument) : Query::cursorBefore($cursorDocument);
        }

        $results = $dbForProject->find('teams', \array_merge($filterQueries, $queries));
        $total = $dbForProject->count('teams', $filterQueries, APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'teams' => $results,
            'total' => $total,
        ]), Response::MODEL_TEAM_LIST);
    });

App::get('/v1/teams/:teamId')
    ->desc('Get Team')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/teams/get-team.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TEAM)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $teamId, Response $response, Database $dbForProject) {

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $response->dynamic($team, Response::MODEL_TEAM);
    });

App::put('/v1/teams/:teamId')
    ->desc('Update Team')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].update')
    ->label('scope', 'teams.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/teams/update-team.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TEAM)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('name', null, new Text(128), 'New team name. Max length: 128 chars.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('audits')
    ->action(function (string $teamId, string $name, Response $response, Database $dbForProject, Event $events, EventAudit $audits) {

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $team = $dbForProject->updateDocument('teams', $team->getId(), $team
            ->setAttribute('name', $name)
            ->setAttribute('search', implode(' ', [$teamId, $name])));

        $events->setParam('teamId', $team->getId());
        $audits->setResource('team/' . $team->getId());

        $response->dynamic($team, Response::MODEL_TEAM);
    });

App::delete('/v1/teams/:teamId')
    ->desc('Delete Team')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].delete')
    ->label('scope', 'teams.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/teams/delete-team.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('deletes')
    ->inject('audits')
    ->action(function (string $teamId, Response $response, Database $dbForProject, Event $events, Delete $deletes, EventAudit $audits) {

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $memberships = $dbForProject->find('memberships', [
            Query::equal('teamId', [$teamId]),
            Query::limit(2000), // TODO fix members limit
        ]);

        // TODO delete all members individually from the user object
        foreach ($memberships as $membership) {
            if (!$dbForProject->deleteDocument('memberships', $membership->getId())) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove membership for team from DB');
            }
        }

        if (!$dbForProject->deleteDocument('teams', $teamId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove team from DB');
        }

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($team);

        $events
            ->setParam('teamId', $team->getId())
            ->setPayload($response->output($team, Response::MODEL_TEAM))
        ;

        $audits
            ->setParam('event', 'teams.delete')
            ->setParam('resource', 'team/' . $teamId)
            ->setParam('data', $team->getArrayCopy())
        ;

        $response->noContent();
    });

App::post('/v1/teams/:teamId/memberships')
    ->desc('Create Team Membership')
    ->groups(['api', 'teams', 'auth'])
    ->label('event', 'teams.[teamId].memberships.[membershipId].create')
    ->label('scope', 'teams.write')
    ->label('auth.type', 'invites')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'createMembership')
    ->label('sdk.description', '/docs/references/teams/create-team-membership.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MEMBERSHIP)
    ->label('abuse-limit', 10)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('email', '', new Email(), 'Email of the new team member.')
    ->param('roles', [], new ArrayList(new Key(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of strings. Use this param to set the user roles in the team. A role can be any string. Learn more about [roles and permissions](/docs/permissions). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 32 characters long.')
    ->param('url', '', fn($clients) => new Host($clients), 'URL to redirect the user back to your app from the invitation email.  Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients']) // TODO add our own built-in confirm page
    ->param('name', '', new Text(128), 'Name of the new team member. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('audits')
    ->inject('mails')
    ->inject('events')
    ->action(function (string $teamId, string $email, array $roles, string $url, string $name, Response $response, Document $project, Document $user, Database $dbForProject, Locale $locale, EventAudit $audits, Mail $mails, Event $events) {

        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());
        $isAppUser = Auth::isAppUser(Authorization::getRoles());

        if (!$isPrivilegedUser && !$isAppUser && empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED);
        }

        $email = \strtolower($email);
        $name = (empty($name)) ? $email : $name;
        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $invitee = $dbForProject->findOne('users', [Query::equal('email', [$email])]); // Get user by email address

        if (empty($invitee)) { // Create new user if no user with same email found
            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if ($limit !== 0 && $project->getId() !== 'console') { // check users limit, console invites are allways allowed.
                $total = $dbForProject->count('users', [], APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception(Exception::USER_COUNT_EXCEEDED, 'Project registration is restricted. Contact your administrator for more information.');
                }
            }

            try {
                $userId = $dbForProject->getId();
                $invitee = Authorization::skip(fn() => $dbForProject->createDocument('users', new Document([
                    '$id' => $userId,
                    '$read' => ['user:' . $userId, 'role:all'],
                    '$write' => ['user:' . $userId],
                    'email' => $email,
                    'emailVerification' => false,
                    'status' => true,
                    'password' => Auth::passwordHash(Auth::passwordGenerator()),
                    /**
                     * Set the password update time to 0 for users created using
                     * team invite and OAuth to allow password updates without an
                     * old password
                     */
                    'passwordUpdate' => null,
                    'registration' => DateTime::now(),
                    'reset' => false,
                    'name' => $name,
                    'prefs' => new \stdClass(),
                    'sessions' => null,
                    'tokens' => null,
                    'memberships' => null,
                    'search' => implode(' ', [$userId, $email, $name])
                ])));
            } catch (Duplicate $th) {
                throw new Exception(Exception::USER_ALREADY_EXISTS);
            }
        }

        $isOwner = Authorization::isRole('team:' . $team->getId() . '/owner');

        if (!$isOwner && !$isPrivilegedUser && !$isAppUser) { // Not owner, not admin, not app (server)
            throw new Exception(Exception::USER_UNAUTHORIZED, 'User is not allowed to send invitations for this team');
        }

        $secret = Auth::tokenGenerator();

        $membershipId = $dbForProject->getId();
        $membership = new Document([
            '$id' => $membershipId,
            '$read' => ['role:all'],
            '$write' => ['user:' . $invitee->getId(), 'team:' . $team->getId() . '/owner'],
            'userId' => $invitee->getId(),
            'userInternalId' => $invitee->getInternalId(),
            'teamId' => $team->getId(),
            'teamInternalId' => $team->getInternalId(),
            'roles' => $roles,
            'invited' => DateTime::now(),
            'joined' => ($isPrivilegedUser || $isAppUser) ? DateTime::now() : null,
            'confirm' => ($isPrivilegedUser || $isAppUser),
            'secret' => Auth::hash($secret),
            'search' => implode(' ', [$membershipId, $invitee->getId()])
        ]);

        if ($isPrivilegedUser || $isAppUser) { // Allow admin to create membership
            try {
                $membership = Authorization::skip(fn() => $dbForProject->createDocument('memberships', $membership));
            } catch (Duplicate $th) {
                throw new Exception(Exception::TEAM_INVITE_ALREADY_EXISTS);
            }
            $team->setAttribute('total', $team->getAttribute('total', 0) + 1);
            $team = Authorization::skip(fn() => $dbForProject->updateDocument('teams', $team->getId(), $team));

            $dbForProject->deleteCachedDocument('users', $invitee->getId());
        } else {
            try {
                $membership = $dbForProject->createDocument('memberships', $membership);
            } catch (Duplicate $th) {
                throw new Exception(Exception::TEAM_INVITE_ALREADY_EXISTS);
            }
        }

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['membershipId' => $membership->getId(), 'userId' => $invitee->getId(), 'secret' => $secret, 'teamId' => $teamId]);
        $url = Template::unParseURL($url);

        if (!$isPrivilegedUser && !$isAppUser) { // No need of confirmation when in admin or app mode
            $mails
                ->setType(MAIL_TYPE_INVITATION)
                ->setRecipient($email)
                ->setUrl($url)
                ->setName($name)
                ->setLocale($locale->default)
                ->setTeam($team)
                ->setUser($user)
                ->trigger()
            ;
        }

        $audits
            ->setResource('team/' . $teamId)
        ;

        $events
            ->setParam('teamId', $team->getId())
            ->setParam('membershipId', $membership->getId())
        ;

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic(
            $membership
            ->setAttribute('teamName', $team->getAttribute('name'))
            ->setAttribute('userName', $invitee->getAttribute('name'))
            ->setAttribute('userEmail', $invitee->getAttribute('email')),
            Response::MODEL_MEMBERSHIP
        );
    });

App::get('/v1/teams/:teamId/memberships')
    ->desc('Get Team Memberships')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'getMemberships')
    ->label('sdk.description', '/docs/references/teams/get-team-members.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MEMBERSHIP_LIST)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of memberships to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the membership used as the starting point for the query, excluding the membership itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor, can be either \'before\' or \'after\'.', true)
    ->param('orderType', Database::ORDER_ASC, new WhiteList([Database::ORDER_ASC, Database::ORDER_DESC], true), 'Order result by ' . Database::ORDER_ASC . ' or ' . Database::ORDER_DESC . ' order.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $teamId, string $search, int $limit, int $offset, string $cursor, string $cursorDirection, string $orderType, Response $response, Database $dbForProject) {

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $filterQueries = [Query::equal('teamId', [$teamId])];

        if (!empty($search)) {
            $filterQueries[] = Query::search('search', $search);
        }

        $otherQueries = [];
        $otherQueries[] = Query::limit($limit);
        $otherQueries[] = Query::offset($offset);
        $otherQueries[] = $orderType === Database::ORDER_ASC ? Query::orderAsc('') : Query::orderDesc('');
        if (!empty($cursor)) {
            $cursorDocument = $dbForProject->getDocument('memberships', $cursor);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Membership '{$cursor}' for the 'cursor' value not found.");
            }

            $otherQueries[] = $cursorDirection === Database::CURSOR_AFTER ? Query::cursorAfter($cursorDocument) : Query::cursorBefore($cursorDocument);
        }

        $memberships = $dbForProject->find(
            collection: 'memberships',
            queries: \array_merge($filterQueries, $otherQueries),
        );

        $total = $dbForProject->count(
            collection: 'memberships',
            queries: $filterQueries,
            max: APP_LIMIT_COUNT
        );

        $memberships = array_filter($memberships, fn(Document $membership) => !empty($membership->getAttribute('userId')));

        $memberships = array_map(function ($membership) use ($dbForProject, $team) {
            $user = $dbForProject->getDocument('users', $membership->getAttribute('userId'));

            $membership
                ->setAttribute('teamName', $team->getAttribute('name'))
                ->setAttribute('userName', $user->getAttribute('name'))
                ->setAttribute('userEmail', $user->getAttribute('email'))
            ;

            return $membership;
        }, $memberships);

        $response->dynamic(new Document([
            'memberships' => $memberships,
            'total' => $total,
        ]), Response::MODEL_MEMBERSHIP_LIST);
    });

App::get('/v1/teams/:teamId/memberships/:membershipId')
    ->desc('Get Team Membership')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'getMembership')
    ->label('sdk.description', '/docs/references/teams/get-team-member.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MEMBERSHIP_LIST)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $teamId, string $membershipId, Response $response, Database $dbForProject) {

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $membership = $dbForProject->getDocument('memberships', $membershipId);

        if ($membership->isEmpty() || empty($membership->getAttribute('userId'))) {
            throw new Exception(Exception::MEMBERSHIP_NOT_FOUND);
        }

        $user = $dbForProject->getDocument('users', $membership->getAttribute('userId'));

        $membership
            ->setAttribute('teamName', $team->getAttribute('name'))
            ->setAttribute('userName', $user->getAttribute('name'))
            ->setAttribute('userEmail', $user->getAttribute('email'))
        ;

        $response->dynamic($membership, Response::MODEL_MEMBERSHIP);
    });

App::patch('/v1/teams/:teamId/memberships/:membershipId')
    ->desc('Update Membership Roles')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].memberships.[membershipId].update')
    ->label('scope', 'teams.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'updateMembershipRoles')
    ->label('sdk.description', '/docs/references/teams/update-team-membership-roles.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MEMBERSHIP)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->param('roles', [], new ArrayList(new Key(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of strings. Use this param to set the user\'s roles in the team. A role can be any string. Learn more about [roles and permissions](https://appwrite.io/docs/permissions). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 32 characters long.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('events')
    ->action(function (string $teamId, string $membershipId, array $roles, Request $request, Response $response, Document $user, Database $dbForProject, EventAudit $audits, Event $events) {

        $team = $dbForProject->getDocument('teams', $teamId);
        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $membership = $dbForProject->getDocument('memberships', $membershipId);
        if ($membership->isEmpty()) {
            throw new Exception(Exception::MEMBERSHIP_NOT_FOUND);
        }

        $profile = $dbForProject->getDocument('users', $membership->getAttribute('userId'));
        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());
        $isAppUser = Auth::isAppUser(Authorization::getRoles());
        $isOwner = Authorization::isRole('team:' . $team->getId() . '/owner');

        if (!$isOwner && !$isPrivilegedUser && !$isAppUser) { // Not owner, not admin, not app (server)
            throw new Exception(Exception::USER_UNAUTHORIZED, 'User is not allowed to modify roles');
        }

        /**
         * Update the roles
         */
        $membership->setAttribute('roles', $roles);
        $membership = $dbForProject->updateDocument('memberships', $membership->getId(), $membership);

        /**
         * Replace membership on profile
         */
        $dbForProject->deleteCachedDocument('users', $profile->getId());

        $audits->setResource('team/' . $teamId);

        $events
            ->setParam('teamId', $team->getId())
            ->setParam('membershipId', $membership->getId());

        $response->dynamic(
            $membership
                ->setAttribute('teamName', $team->getAttribute('name'))
                ->setAttribute('userName', $profile->getAttribute('name'))
                ->setAttribute('userEmail', $profile->getAttribute('email')),
            Response::MODEL_MEMBERSHIP
        );
    });

App::patch('/v1/teams/:teamId/memberships/:membershipId/status')
    ->desc('Update Team Membership Status')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].memberships.[membershipId].update.status')
    ->label('scope', 'public')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'updateMembershipStatus')
    ->label('sdk.description', '/docs/references/teams/update-team-membership-status.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MEMBERSHIP)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->param('userId', '', new UID(), 'User ID.')
    ->param('secret', '', new Text(256), 'Secret key.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('geodb')
    ->inject('audits')
    ->inject('events')
    ->action(function (string $teamId, string $membershipId, string $userId, string $secret, Request $request, Response $response, Document $user, Database $dbForProject, Reader $geodb, EventAudit $audits, Event $events) {
        $protocol = $request->getProtocol();

        $membership = $dbForProject->getDocument('memberships', $membershipId);

        if ($membership->isEmpty()) {
            throw new Exception(Exception::MEMBERSHIP_NOT_FOUND);
        }

        if ($membership->getAttribute('teamId') !== $teamId) {
            throw new Exception(Exception::TEAM_MEMBERSHIP_MISMATCH);
        }

        $team = Authorization::skip(fn() => $dbForProject->getDocument('teams', $teamId));

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        if (Auth::hash($secret) !== $membership->getAttribute('secret')) {
            throw new Exception(Exception::TEAM_INVALID_SECRET);
        }

        if ($userId !== $membership->getAttribute('userId')) {
            throw new Exception(Exception::TEAM_INVITE_MISMATCH, 'Invite does not belong to current user (' . $user->getAttribute('email') . ')');
        }

        if ($user->isEmpty()) {
            $user = $dbForProject->getDocument('users', $userId); // Get user
        }

        if ($membership->getAttribute('userId') !== $user->getId()) {
            throw new Exception(Exception::TEAM_INVITE_MISMATCH, 'Invite does not belong to current user (' . $user->getAttribute('email') . ')');
        }

        if ($membership->getAttribute('confirm') === true) {
            throw new Exception(Exception::MEMBERSHIP_ALREADY_CONFIRMED);
        }

        $membership // Attach user to team
            ->setAttribute('joined', DateTime::now())
            ->setAttribute('confirm', true)
        ;

        $user
            ->setAttribute('emailVerification', true)
        ;

        // Log user in

        Authorization::setRole('user:' . $user->getId());

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $expire = DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_LOGIN_LONG);
        $secret = Auth::tokenGenerator();
        $session = new Document(array_merge([
            '$id' => $dbForProject->getId(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'provider' => Auth::SESSION_PROVIDER_EMAIL,
            'providerUid' => $user->getAttribute('email'),
            'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
            'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
        ], $detector->getOS(), $detector->getClient(), $detector->getDevice()));

        $session = $dbForProject->createDocument('sessions', $session
            ->setAttribute('$read', ['user:' . $user->getId()])
            ->setAttribute('$write', ['user:' . $user->getId()]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        Authorization::setRole('user:' . $userId);

        $membership = $dbForProject->updateDocument('memberships', $membership->getId(), $membership);

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $team = Authorization::skip(fn() => $dbForProject->updateDocument('teams', $team->getId(), $team->setAttribute('total', $team->getAttribute('total', 0) + 1)));

        $audits->setResource('team/' . $teamId);

        $events
            ->setParam('teamId', $team->getId())
            ->setParam('membershipId', $membership->getId())
        ;

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]))
            ;
        }

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
        ;

        $response->dynamic(
            $membership
            ->setAttribute('teamName', $team->getAttribute('name'))
            ->setAttribute('userName', $user->getAttribute('name'))
            ->setAttribute('userEmail', $user->getAttribute('email')),
            Response::MODEL_MEMBERSHIP
        );
    });

App::delete('/v1/teams/:teamId/memberships/:membershipId')
    ->desc('Delete Team Membership')
    ->groups(['api', 'teams'])
    ->label('event', 'teams.[teamId].memberships.[membershipId].delete')
    ->label('scope', 'teams.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'deleteMembership')
    ->label('sdk.description', '/docs/references/teams/delete-team-membership.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('teamId', '', new UID(), 'Team ID.')
    ->param('membershipId', '', new UID(), 'Membership ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('events')
    ->action(function (string $teamId, string $membershipId, Response $response, Database $dbForProject, EventAudit $audits, Event $events) {

        $membership = $dbForProject->getDocument('memberships', $membershipId);

        if ($membership->isEmpty()) {
            throw new Exception(Exception::TEAM_INVITE_NOT_FOUND);
        }

        if ($membership->getAttribute('teamId') !== $teamId) {
            throw new Exception(Exception::TEAM_MEMBERSHIP_MISMATCH);
        }

        $user = $dbForProject->getDocument('users', $membership->getAttribute('userId'));

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        try {
            $dbForProject->deleteDocument('memberships', $membership->getId());
        } catch (AuthorizationException $exception) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (\Exception $exception) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove membership from DB');
        }

        $dbForProject->deleteCachedDocument('users', $user->getId());

        if ($membership->getAttribute('confirm')) { // Count only confirmed members
            $team->setAttribute('total', \max($team->getAttribute('total', 0) - 1, 0));
            Authorization::skip(fn() => $dbForProject->updateDocument('teams', $team->getId(), $team));
        }

        $audits->setResource('team/' . $teamId);

        $events
            ->setParam('teamId', $team->getId())
            ->setParam('membershipId', $membership->getId())
            ->setPayload($response->output($membership, Response::MODEL_MEMBERSHIP))
        ;

        $response->noContent();
    });

App::get('/v1/teams/:teamId/logs')
    ->desc('List Team Logs')
    ->groups(['api', 'teams'])
    ->label('scope', 'teams.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'teams')
    ->label('sdk.method', 'listLogs')
    ->label('sdk.description', '/docs/references/teams/get-team-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('teamId', null, new UID(), 'Team ID.')
    ->param('limit', 25, new Range(0, 100), 'Maximum number of logs to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function ($teamId, $limit, $offset, $response, $dbForProject, $locale, $geodb) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForProject */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */

        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $audit = new Audit($dbForProject);
        $resource = 'team/' . $team->getId();
        $logs = $audit->getLogsByResource($resource, $limit, $offset);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => $log['userId'],
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'mode' => $log['data']['mode'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }
        $response->dynamic(new Document([
            'total' => $audit->countLogsByResource($resource),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });
