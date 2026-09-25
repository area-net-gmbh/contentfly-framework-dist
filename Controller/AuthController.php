<?php
namespace Areanet\PIM\Controller;
use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Event;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Classes\Controller\BaseController;
use Areanet\PIM\Classes\Security\ExternalIdentity;
use Areanet\PIM\Classes\Security\TokenHandler;
use Areanet\PIM\Classes\Security\JwtAccessToken;
use Areanet\PIM\Entity\RevokedToken;
use Areanet\PIM\Entity\Token;
use Areanet\PIM\Entity\User;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

use Symfony\Component\HttpFoundation\Request;


class AuthController extends BaseController
{
    /*
     * CHECK_LOGIN_INTERVAL AND MIN_LOGIN_INTERVAL ARE GONE (013-001-0003).
     *
     * Two constants used to live here, and further down a branch that never ran:
     * `CHECK_LOGIN_INTERVAL` was permanently `false`. Even switched on it would have been a 60-second
     * gap per user, measured from the last issued token — ineffective against guessing across many
     * accounts, and against guessing many passwords for ONE account only if a token was created in
     * between. An attacker who never guesses right never issues a token.
     *
     * It is replaced by `Areanet\PIM\Classes\Security\LoginThrottle`: per identifier AND per IP, with
     * increasing delay, and without a switch that turns it off.
     */

    /**
     * @apiVersion 2.0.0
     * @api {post} /auth/login login
     * @apiName Login
     * @apiGroup User
     * @apiDescription API endpoint for authenticating a user.
     *
     * A login provider (parameter `loginManager`) can extend or replace Contentfly's default password login.
     * @apiHeader {String} Authorization <code>Bearer &lt;token&gt;</code> — the token from /auth/login. The legacy header <code>appcms-token</code> is still accepted.
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} alias User name
     * @apiParam {String} pass Password
     * @apiParam {String} loginManager Optional name of a registered login provider
     * @apiParam {String} tokenType "jwt" for an access JWT plus refresh token; without it an opaque token (013-003-0001)
     * @apiParam {Boolean} withSchema Return the schema
     * @apiParamExample {json} Request example:
     *     {
     *      "alias": "admin",
     *      "pass": "xyz"
     *     }
     * @apiSuccessExample {json} Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "data": {
     *         "token": "765040cd53f2b7f8dd25c07cee4bc998c7be51162028b68304e3a2a327014a94",
     *         "user": {
     *           "id": "b6d69325-a864-11f1-8eb9-e2354fd57eaa",
     *           "isIntern": false,
     *           "isAdmin": true,
     *           "alias": "admin",
     *           "isActive": true,
     *           "loginManager": ""
     *         },
     *         "tempData": {
     *           "loginProvider": null,
     *           "externalGroups": []
     *         }
     *       },
     *       "errors": null,
     *       "meta": {
     *         "ts": "2026-09-21 08:09:28",
     *         "version": "2.4.0",
     *         "projectVersion": "1.0.0",
     *         "hash": "4f3e247083bdb20bc3c11be6a28f9b65"
     *       }
     *     }
     * @apiSuccessExample {json} Success-Response with tokenType jwt:
     *     HTTP/1.1 200 OK
     *     {
     *       "data": {
     *         "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJhZG1pbiJ9.signature",
     *         "user": {
     *           "id": "b6d69325-a864-11f1-8eb9-e2354fd57eaa",
     *           "isIntern": false,
     *           "isAdmin": true,
     *           "alias": "admin",
     *           "isActive": true,
     *           "loginManager": ""
     *         },
     *         "refreshToken": "57d21cea13818e340e0b498eccce3e4f9f4e4763c633af90a56aaa2fc80a3b3d",
     *         "expiresIn": 900,
     *         "tempData": {
     *           "loginProvider": null,
     *           "externalGroups": []
     *         }
     *       },
     *       "errors": null,
     *       "meta": {
     *         "ts": "2026-09-21 08:09:28",
     *         "version": "2.4.0",
     *         "projectVersion": "1.0.0",
     *         "hash": "4f3e247083bdb20bc3c11be6a28f9b65"
     *       }
     *     }
     * @apiErrorExample {json} Error-Response:
     *     HTTP/1.1 401 Unauthorized
     *     {
     *       "data": null,
     *       "errors": [
     *         {
     *           "code": "contentfly_general_invalid_credentials",
     *           "detail": "Invalid user name and/or password.",
     *           "type": "Areanet\\PIM\\Controller\\AuthController",
     *           "context": null
     *         }
     *       ],
     *       "meta": {
     *         "ts": "2026-09-21 08:09:28",
     *         "version": "2.4.0",
     *         "projectVersion": "1.0.0",
     *         "hash": "4f3e247083bdb20bc3c11be6a28f9b65"
     *       }
     *     }
     * @apiError 401 Invalid user name | The user is deactivated | Invalid user name and/or password
     * @apiError 429 Too many login attempts - the throttle applies per identifier and per IP (013-001-0003)
     */
    public function loginAction(Request $request)
    {
        $identifier = ($request->request->all()['alias'] ?? null);
        $ip         = $request->getClientIp();

        /** @var \Areanet\PIM\Classes\Security\LoginThrottle $throttle */
        $throttle = $this->app['loginThrottle'];

        /*
         * THROTTLE FIRST, THEN VERIFY (013-001-0003).
         *
         * The order is the point: whoever is over the limit never gets as far as the database query and
         * the password comparison. If the throttle came after the check, every rejected attempt would
         * still cost an Argon2id run — the block would itself become the lever for an overload.
         *
         * THE RESPONSE SAYS NOTHING ABOUT THE IDENTIFIER. It is the same for a known and an invented
         * user name; otherwise the throttle would be an oracle for which accounts exist. `Retry-After`
         * only says how long to wait — the legitimate user who mistyped three times is entitled to that.
         */
        if (($retryAfter = $throttle->retryAfter($identifier, $ip)) !== null) {
            return $this->renderError(
                Messages::contentfly_general_too_many_attempts,
                'Too many login attempts. Please try again later.',
                429,
                array('Retry-After' => $retryAfter)
            );
        }

        /*
         * EVERY FAILURE GOES THROUGH THIS ONE PLACE.
         *
         * Before, five `return new JsonResponse(..., 401)` stood side by side. Whoever puts a
         * `$throttle->recordFailure(...)` in front of each one in turn forgets one eventually — and a
         * single uncounted branch is the way to guess past the throttle.
         */
        $reject = function ($message) use ($throttle, $identifier, $ip) {
            $throttle->recordFailure($identifier, $ip);

            // ONE CODE FOR ALL 401s of the login, and that is deliberate (011-001-0004): unknown
            // user, wrong password, rejected provider — a client must not be able to tell them
            // apart, or the answer becomes an oracle for which accounts exist. The wording of
            // `detail` is already uniform for the same reason.
            return $this->renderError(Messages::contentfly_general_invalid_credentials, $message, 401);
        };

        /*
         * THE NAME SELECTS FROM AN ALLOWLIST, NOT A CLASS (013-004-0001).
         *
         * This used to take the class name from the request, resolved to `Custom\Classes\<Name>`. The
         * prefix and an `instanceof` check limited the damage — but the choice lay with the caller, and
         * which class an application instantiates is a decision for the operator.
         *
         * The parameter is still called `loginManager`: existing clients send it that way, and renaming
         * it would break the wire format without any gain within this story. What it names is now an
         * entry in the registry — a class name is not in there and is therefore rejected like any other
         * unknown name.
         */
        $providerName  = ($request->request->all()['loginManager'] ?? null);
        $loginProvider = null;

        if (!empty($providerName)) {
            $loginProvider = $this->app['loginProviders']->get(is_string($providerName) ? $providerName : null);

            /*
             * AN UNKNOWN NAME IS REJECTED, not passed on to the password check. Otherwise a typo in the
             * provider name would be a silent login through the wrong path — and a guessed name an
             * oracle for which external systems this installation knows.
             */
            if (!$loginProvider) {
                return $reject('Invalid user name.');
            }
        }

        if ($loginProvider) {
            try {
                $identity = $loginProvider->authenticate($request);
            } catch (\Throwable) {
                /*
                 * An exception is a rejection as well. Passing its message to the outside — which is
                 * how it worked until 013-004-0001 — turned the provider into a storyteller: an LDAP
                 * error appeared verbatim in the response, server name included.
                 */
                $identity = null;
            }

            if (!$identity instanceof ExternalIdentity) {
                return $reject('Invalid user name and/or password.');
            }

            /*
             * PROVISIONING IN THE FRAMEWORK (013-004-0002).
             *
             * Whoever comes through an external system for the first time gets an account here — with a
             * LOCKED password, not with their user name as password (finding A-6). Whoever already has
             * one is found again through provider AND identifier.
             *
             * The provider therefore has nothing to do with the database any more. Before, it called
             * `createManagedUser()` itself — every project on its own, each with its own mistakes.
             */
            $provider = strtolower(trim((string) $providerName));

            $user = $this->app['userProvisioning']->findOrCreate($provider, $identity);

            /*
             * GROUP AND ADMIN FLAG ON EVERY LOGIN (013-004-0003).
             *
             * Not only on creation: whoever drops out of a group in the external system drops out here
             * on the next login. That is exactly why roles and groups are NOT in the JWT (013-003-0001)
             * — they would be frozen there until it expires.
             */
            $this->app['groupMapping']->apply($provider, $identity, $user);

            if (!$user->getIsActive()) {
                return $reject('The user is deactivated.');
            }
        }else{

            $user = $this->em->getRepository('Areanet\PIM\Entity\User')->findOneBy(array('alias' => $identifier));
            if(!$user){
                return $reject('Invalid user name.');
            }

            if(!$user->getIsActive()){
                return $reject('The user is deactivated.');
            }

            if($user->getLoginManager()){
                return $reject('The user can only be authenticated through their login provider.');
            }

            /*
             * ONE BRANCH, NO CASE DISTINCTION (013-001-0002).
             *
             * A second condition used to be here: if APP_MASTER_PASSWORD was set, that one value was
             * enough for EVERY user. The constant is gone without replacement — a switch that grants
             * full access is a back door even when switched off.
             */
            if(!$user->isPass(($request->request->all()['pass'] ?? null))){
                return $reject('Invalid user name and/or password.');
            }
        }

        /*
         * REHASHING ON LOGIN (013-001-0001).
         *
         * If the password matches and the hash is still in the old SHA-256 format — or uses outdated
         * parameters — it is replaced here. No forced reset, no migration in advance: after each
         * user's first login the old hash is gone.
         *
         * IT LIVES HERE AND NOT IN `isPass()`: a check must not write anything. Otherwise every call
         * would have a side effect, including the one from `Api::doUpdate()`, where the current
         * password is only being confirmed.
         *
         * The login-provider path is excluded — an external system verifies there, and
         * `$user->getPass()` has no relation to the word that was entered.
         */
        if (!$loginProvider && $user->needsRehash()) {
            $user->setPass(($request->request->all()['pass'] ?? null));
            $this->em->flush();
        }

        /*
         * THE SUCCESSFUL LOGIN CLEARS THIS IDENTIFIER'S COUNTER (013-001-0003).
         *
         * Only the identifier's, not the IP's: otherwise a single valid account — the attacker's own —
         * would be enough to unlock themselves after every block.
         */
        $throttle->reset($identifier);

        /*
         * WHICH TOKEN TYPE THE LOGIN ISSUES (013-003-0001).
         *
         * ONLY ON REQUEST, and explicitly NOT through a configuration switch. Such a switch would flip
         * the response for EVERY client at once — and the promise of this story is that an existing
         * client notices nothing. Whoever wants a JWT says so per request; whoever says nothing gets
         * what they always got.
         */
        $wantsJwt = strtolower((string) ($request->request->all()['tokenType'] ?? '')) === 'jwt';

        if ($wantsJwt && !JwtAccessToken::isConfigured()) {
            /*
             * The message is aimed at the operator, not the caller: it names the missing field and
             * nothing else. It says nothing about accounts, passwords or existing tokens — an attacker
             * only learns that this installation does not issue JWTs.
             */
            return $this->renderError(
                Messages::contentfly_general_jwt_not_configured,
                'JWTs are not configured on this installation: SECURITY_JWT_SECRET is missing.',
                500
            );
        }

        /*
         * THE PROJECT'S TURN, AFTER THE CHECK AND BEFORE THE TOKEN (000-000-0045).
         *
         * The provider contract gives a provider one duty and keeps it away from the database. What an
         * old LoginManager did beyond that — set fields on the user, hand the client extra data as
         * `tempData` — has its place here. Found on the existing project UFP (007-005-0004), whose app
         * reads `data.role` on every login.
         *
         * Every way that reaches this line has succeeded: password or provider, account active, JWT
         * configured if requested. A rejected login never fires the event, so a listener never acts on
         * a failed attempt.
         *
         * Params: `user`, `request`, `provider` (the registered name, or null for the password path),
         * `identity` (the ExternalIdentity, or null) and `app`, like the other `pim.*` events.
         *
         * What a listener changes on the user is written with the token below — same EntityManager,
         * one flush — and `user`/`data` in the response are built after it. A listener that throws
         * ends the login without a token; that is its decision to make.
         */
        $event = new Event();
        $event->setParam('user', $user);
        $event->setParam('request', $request);
        $event->setParam('provider', $loginProvider ? strtolower(trim((string) $providerName)) : null);
        $event->setParam('identity', $loginProvider ? $identity : null);
        $event->setParam('app', $this->app);
        $this->app['dispatcher']->dispatch($event, 'pim.auth.after.login');

        $token = new Token();
        $token->setUser($user);

        if ($wantsJwt) {
            // The row becomes the refresh token. That makes it useless as an access token — the opaque
            // branch of the TokenHandler rejects it.
            $token->setPurpose(Token::PURPOSE_REFRESH);
        }

        $this->em->persist($token);
        $this->em->flush();

        $this->app['auth.user'] = $user;

        $response = array(
            // getPlaintext(), not getToken(): since 013-001-0004 the column only holds the hash. This is
            // the only place and the only moment at which the token itself leaves the system — after
            // that it only exists at the client.
            'token' => $token->getPlaintext(),
            'user' => $user->toValueObject($this->app, 'PIM\User', false)
        );

        if ($wantsJwt) {
            $access = JwtAccessToken::issue($user);

            /*
             * `token` stays what the client presents — now simply the access JWT. For a client that
             * switches over exactly one field value changes and no field name. The refresh token comes
             * next to it; it can be redeemed from 013-003-0002 on.
             */
            $response['token']        = $access['token'];
            $response['refreshToken'] = $token->getPlaintext();
            $response['expiresIn']    = $access['exp'] - time();
        }

        /*
         * `data` INSIDE `data` WOULD HAVE BEEN THE NAME (011-001-0004).
         *
         * What a project hands the client on login was called `data` at the top level of the
         * response. Under the envelope that would read `body.data.data` — so it is now called what
         * the entity field it comes from is called. Named in the register; it is one line for a
         * client, the same as everything else in this response.
         */
        if(($tempData = $user->getTempData())){
            $response['tempData'] = $tempData;
        }

        $meta = array();

        /*
         * withSchema ANSWERS EXACTLY LIKE /api/schema (011-001-0004).
         *
         * Both used to hand over the whole `getExtendedSchema()` in one lump — but /api/schema
         * moved with 011-001-0002: `data` is the entity map, the rights and the rest annotate it
         * and sit in `meta`. If the split were different here, a client would need a second reader
         * for the same schema depending on where it came from, which is the one thing this epic is
         * about.
         *
         * `hash` is gone from here: `meta.hash` carries it in EVERY answer.
         */
        if(($request->request->all()['withSchema'] ?? null)){
            $api      = new Api($this->app);
            $extended = $api->getExtendedSchema();

            $response['schema']     = $extended['data'];
            $meta['permissions']     = $extended['permissions'];
            $meta['i18nPermissions'] = $extended['i18nPermissions'];
            $meta['devmode']         = $extended['devmode'];
            $meta['frontend']        = $extended['frontend'];
        }

        return $this->renderResponse($response, 200, $meta);
    }

    /**
     * @apiVersion 2.0.0
     * @api {post} /auth/refresh refresh
     * @apiName Refresh
     * @apiGroup User
     * @apiDescription Exchanges a refresh token for a fresh access JWT (013-003-0002).
     *
     * @apiParam {String} refreshToken The refresh token from the login
     * @apiSuccessExample {json} Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "data": {
     *         "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJhZG1pbiJ9.signature",
     *         "refreshToken": "1934c0c2df8dc487b2a161e09ffc8bd5c0a9a129bdbdcbd6e944c920be8f345c",
     *         "expiresIn": 900
     *       },
     *       "errors": null,
     *       "meta": {
     *         "ts": "2026-09-21 08:09:28",
     *         "version": "2.4.0",
     *         "projectVersion": "1.0.0",
     *         "hash": "4f3e247083bdb20bc3c11be6a28f9b65"
     *       }
     *     }
     * @apiError 401 Invalid refresh token
     * @apiError 429 Too many attempts
     */
    public function refreshAction(Request $request)
    {
        $ip       = $request->getClientIp();
        $throttle = $this->app['loginThrottle'];

        /*
         * THE THROTTLE APPLIES HERE AS WELL (013-003-0002).
         *
         * An endpoint that issues access tokens is the same target as the login. Leaving it unthrottled
         * would mean mounting the throttle on the front door and leaving the side door open.
         *
         * THROTTLING IS BY ADDRESS ONLY, not by identifier: the request does not carry one. That is also
         * the right axis — a refresh token cannot be guessed through a user name, only by trying from
         * one place.
         */
        if (($retryAfter = $throttle->retryAfter(null, $ip)) !== null) {
            return $this->renderError(
                Messages::contentfly_general_too_many_attempts,
                'Too many login attempts. Please try again later.',
                429,
                array('Retry-After' => $retryAfter)
            );
        }

        /*
         * EVERY FAILURE LOOKS THE SAME.
         *
         * Unknown, expired, already used, deactivated user, or an access JWT at the wrong door — the
         * caller only learns that it was not enough. Whoever distinguishes here tells an attacker which
         * of their attempts came closer.
         */
        $reject = function () use ($throttle, $ip) {
            $throttle->recordFailure(null, $ip);

            return $this->renderError(Messages::contentfly_general_invalid_refresh_token, 'Invalid refresh token.', 401);
        };

        $presented = ($request->request->all()['refreshToken'] ?? null);

        if (!is_string($presented) || $presented === '') {
            return $reject();
        }

        $row = $this->em->getRepository('Areanet\PIM\Entity\Token')->findOneBy(
            array('token' => Token::hash($presented))
        );

        // No match — or a match that is not a refresh token. An access token does not work here:
        // otherwise the separation from 013-003-0001 would be open again in one direction.
        if (!$row instanceof Token || !$row->isRefreshToken()) {
            return $reject();
        }

        $user = $row->getUser();

        if (!$user || !$user->getIsActive()) {
            return $reject();
        }

        if (TokenHandler::isExpired($row, $user)) {
            $this->em->remove($row);
            $this->em->flush();

            return $reject();
        }

        if (!JwtAccessToken::isConfigured()) {
            return $this->renderError(
                Messages::contentfly_general_jwt_not_configured,
                'JWTs are not configured on this installation: SECURITY_JWT_SECRET is missing.',
                500
            );
        }

        /*
         * ROTATION: THE PRESENTED TOKEN IS REPLACED.
         *
         * A refresh token that is valid several times is a long-lived secret: whoever intercepts it can
         * fetch fresh access tokens with it for as long as they like, and nobody notices. If it is
         * exchanged on every use, a second use of the same token stands out — it is rejected because
         * the row no longer exists.
         *
         * The old row is deleted and a new one created instead of swapping the value in place: a token
         * IS its row, and `created` should say when this token came into being.
         */
        $this->em->remove($row);

        $new = new Token();
        $new->setUser($user);
        $new->setPurpose(Token::PURPOSE_REFRESH);

        $this->em->persist($new);
        $this->em->flush();

        $this->app['auth.user'] = $user;

        $access = JwtAccessToken::issue($user);

        // `message` is gone with 011-001-0004: the status code already says that it worked.
        return $this->renderResponse(array(
            'token'        => $access['token'],
            'refreshToken' => $new->getPlaintext(),
            'expiresIn'    => $access['exp'] - time(),
        ));
    }

    /**
     * @apiVersion 2.0.0
     * @api {get} /auth/logout logout
     * @apiName Logout
     * @apiGroup User
     * @apiHeader {String} Authorization <code>Bearer &lt;token&gt;</code> — the token from /auth/login. The legacy header <code>appcms-token</code> is still accepted.
     * @apiHeader {String} Content-Type=application/json
     * @apiParam {String} refreshToken Optional; whoever logs out with an access JWT also revokes their
     *                                 refresh token with it (013-003-0003)
     * @apiSuccessExample {json} Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "data": null,
     *       "errors": null,
     *       "meta": {
     *         "ts": "2026-09-21 08:09:28",
     *         "version": "2.4.0",
     *         "projectVersion": "1.0.0",
     *         "hash": "4f3e247083bdb20bc3c11be6a28f9b65"
     *       }
     *     }
     */
    public function logoutAction(Request $request)
    {
        /*
         * NOT EVERY LOGIN HAS A ROW (013-002-0004).
         *
         * `$app['auth.token']` carries the row from `pim_token` — in the JWT branch there is none.
         */
        if ($this->app['auth.token']) {
            $this->em->remove($this->app['auth.token']);
        }

        /*
         * LOGGING OUT WITH A STATELESS TOKEN (013-003-0003).
         *
         * Two things, and both are needed:
         *
         *   1. Put the presented access JWT on the revocation list until its `exp`. Otherwise it would
         *      stay valid after logout — for an intercepted token that is exactly the damage.
         *   2. Delete the refresh token. Otherwise the holder immediately fetches a new access JWT, and
         *      step 1 was in vain.
         *
         * THE CLIENT HAS TO SEND ITS REFRESH TOKEN ALONG, and that is a deliberate decision. The access
         * JWT does not say which refresh row it belongs to — the link would otherwise be a sixth claim,
         * and the claim set from `013-003-0001` is deliberately small. Deleting all of the user's refresh
         * rows would be the alternative; that would log them out on all their devices, which nobody
         * expects when logging out on one of them.
         *
         * Without a refresh token sent along, only the access JWT is revoked; the refresh row then
         * expires through its own time limit.
         */
        if (($claims = $this->app['tokenHandler']->lastClaims())) {
            $revoked = new RevokedToken();
            $revoked->setJti((string) $claims['jti']);
            $revoked->setExpiresAt((new \DateTime())->setTimestamp((int) $claims['exp']));

            $this->em->persist($revoked);
        }

        $sentAlong = $request->query->get('refreshToken') ?? ($request->request->all()['refreshToken'] ?? null);

        if (is_string($sentAlong) && $sentAlong !== '') {
            $row = $this->em->getRepository('Areanet\PIM\Entity\Token')->findOneBy(
                array('token' => Token::hash($sentAlong))
            );

            /*
             * ONLY ONE'S OWN. Without this check `logout` would be an endpoint with which any logged-in
             * user could end foreign sessions — they would only have to guess or get hold of a foreign
             * refresh token.
             */
            if ($row instanceof Token
                && $row->isRefreshToken()
                && $row->getUser() === $this->app['auth.user']) {
                $this->em->remove($row);
            }
        }

        $this->em->flush();

        unset($this->app['auth.token']);
        unset($this->app['auth.user']);

        /*
         * NOTHING TO HAND BACK — so `data` is null (011-001-0004).
         *
         * It used to be `{"message": "Logout successful"}`, a sentence a client could only compare
         * against verbatim. The `200` says it, and says it in a way that survives a translation.
         */
        return $this->renderResponse();
    }
}
