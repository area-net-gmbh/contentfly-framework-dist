<?php
namespace Areanet\PIM\Controller;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Controller\BaseController;
use Areanet\PIM\Entity\Folder;
use Areanet\PIM\Entity\Log;
use Areanet\PIM\Entity\ThumbnailSetting;
use Areanet\PIM\Entity\Token;
use Areanet\PIM\Entity\User;
use Doctrine\ORM\Tools\Console\Command\SchemaTool\UpdateCommand;
use Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\SchemaValidator;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Areanet\PIM\Classes\Kernel\Paths;

class SystemController extends BaseController
{

    /**
     * @apiVersion 1.3.0
     * @api {post} /system/do do
     * @apiName Execute
     * @apiDescription Executes system commands.
     * @apiGroup System
     * @apiHeader {String} X-Token Acces-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} method Method to execute
     * @apiParamExample {json} Flush schema cache:
     *     {
     *      "method": "flushSchemaCache",
     *     }
     * @apiParamExample {json} Synchronise database:
     *     {
     *      "method": "updateDatabase",
     *     }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "method": "flushSchemaCache",
     *       "message:" "..."
     *   }
     */
    /**
     * The methods callable via `POST /system/do` (000-000-0015).
     *
     * Spelled out instead of derived: whoever adds a method thereby also decides whether it
     * should be an endpoint.
     */
    private const ERLAUBTE_METHODEN = array(
        'flushSchemaCache',
        'updateDatabase',
        'deleteToken',
        'generateToken',
        'listTokens',
        'addToken',
    );

    public function doAction(Request $request)
    {
        $method = ($request->request->all()['method'] ?? null);

        /*
         * ALLOWLIST INSTEAD OF method_exists (000-000-0015).
         *
         * The gate was `method_exists($this, $method)` — and thus anything the controller or
         * its base class brings along was reachable:
         *
         *   doAction         is public, passes the check and calls itself. The recursion only
         *                    ends at memory_limit — with the default value in a fatal error
         *                    after about 0.2 s, WITHOUT a limit not at all.
         *   setEM,           come from BaseController, get called and only fail at their
         *   __construct      type check. The boundary was drawn by the signature, not by the
         *                    endpoint.
         *
         * The list now names what may be called. It is deliberately spelled out and not
         * derived via reflection: what stands here is a decision, not a property of the
         * class — otherwise every new method would automatically be an endpoint.
         *
         * `method` is missing if the caller does not send it. Up to PHP 8.0,
         * method_exists($this, null) silently returned false; since 8.1 it is a deprecation
         * (000-000-0023).
         */
        if (!is_string($method) || !in_array($method, self::ERLAUBTE_METHODEN, true)) {
            throw new \Exception('Method '.(is_string($method) ? $method : '').' is not available.');
        }

        $date = new \DateTime();

        return new JsonResponse(array('method' => $method, 'datetime' => $date->format('Y-m-d H:i:s'),  'message' => $this->$method($request) ));
    }

    /**
     * Flushes the schema cache: the file and the two Doctrine caches.
     *
     * PSR-6 INSTEAD OF doctrine/cache (010-002-0003). This used to read
     * `getQueryCacheImpl()->deleteAll()`. The `…Impl()` getters still return something today —
     * ORM 2.20 wraps the PSR-6 pool in `Doctrine\Common\Cache\Psr6\DoctrineProvider` — but
     * this bridge lives in `doctrine/cache`, and that package goes away with `010-002-0004`.
     *
     * THE CHECK FOR `null` IS NOT PRECAUTIONARY BUT NECESSARY. The cache is only set up in the
     * bootstrap when `!APP_DEBUG && !APPCMS_CONSOLE` holds; without it the getters return
     * `null`. Previously this read `if(!APP_DEBUG)` instead — the same condition, but only
     * half of it: `APPCMS_CONSOLE` had not been considered. That it worked out was because
     * this method is called via HTTP and the constant is never set there. Two conditions that
     * live in different places and happen to coincide are a temporary arrangement; the method
     * now asks for itself.
     */
    protected function flushSchemaCache(Request $request)
    {
        if(file_exists(Paths::data().'/cache/schema.cache')){
            unlink(Paths::data().'/cache/schema.cache');
        }

        $configuration = $this->app['orm.em']->getConfiguration();

        foreach (array($configuration->getQueryCache(), $configuration->getMetadataCache()) as $cache) {
            if ($cache !== null) {
                $cache->clear();
            }
        }

        return 'Schema cache cleared!';
    }

    protected function updateDatabase(Request $request)
    {

        $schemaTool = new \Doctrine\ORM\Tools\SchemaTool($this->app['orm.em']);
        $classes = $this->app['orm.em']->getMetadataFactory()->getAllMetadata();
        try {
            $schemaTool->updateSchema($classes);
        }catch(\Exception $e){
            return $e->getMessage();
        }

        return "The database was updated successfully.";
    }
    

    protected function deleteToken(Request $request)
    {
        $id =  ($request->request->all()['id'] ?? null);

        $token = $this->em->getRepository('Areanet\\PIM\\Entity\\Token')->find($id);
        if(!$token){
            throw new \Exception('Invalid token');
        }

        $log = new Log();
        $log->setModelId($id);
        $log->setModelName('PIM\\Token');
        $log->setUser($this->app['auth.user']);
        $log->setMode(Log::DELETED);
        /*
         * THE LABEL IS THE HASH, NOT THE TOKEN (013-001-0004).
         *
         * The token used to be stored here in plain text — and `pim_log` is a log that lives
         * longer than the session it describes. A dump of the log therefore handed over the
         * same sessions as a dump of the token table. Since this task, `getToken()` returns the
         * hash; it works just as well as an identifier in the log, but nobody can use it.
         */
        $log->setModelLabel($token->getToken());

        $this->em->remove($token);
        $this->em->persist($log);
        $this->em->flush();

        return true;
    }

    protected function generateToken(Request $request)
    {
        // `random_bytes()` instead of `openssl_random_pseudo_bytes()` (013-001-0004): the latter
        // reports via an output parameter whether the result is cryptographically strong —
        // nobody ever read it. `random_bytes()` returns strong bytes or throws.
        return bin2hex(random_bytes(64));
    }

    protected function listTokens(Request $request)
    {
        $query  = $this->em->createQuery("SELECT token FROM Areanet\PIM\Entity\Token token WHERE token.referrer <> ''");
        $tokens = $query->getResult();

        $data = array();
        foreach($tokens as $token){
            $userData = array(
                'id'        => $token->getUser()->getId(),
                'alias'     => $token->getUser()->getAlias(),
                'active'    => $token->getUser()->getIsActive()
            );

            /*
             * Since 013-001-0004, `token` is the HASH.
             *
             * The token itself can no longer be looked up — not even by the operator. The
             * field stays nonetheless: it identifies the row unambiguously, and whoever holds
             * a token can hash it themselves and thus find out which entry belongs to it. A
             * client that mistakenly presents the value as a token gets a 401 — it fails
             * closed, not open.
             */
            $data[] = array('id' => $token->getId(), 'token' => $token->getToken(), 'referrer' => $token->getReferrer(), 'user' => $userData);
        }

        return $data;
    }

    protected function addToken(Request $request)
    {
        $referrer    =  ($request->request->all()['referrer'] ?? null);
        $tokenString =  ($request->request->all()['token'] ?? null);
        $userId      =  ($request->request->all()['user'] ?? null);

        if(!$referrer || !$tokenString || !$userId){
            throw new \Exception('Invalid token and/or referrer');
        }

        $user = $this->em->getRepository('Areanet\\PIM\\Entity\\User')->find($userId);
        if(!$user){
            throw new \Exception('Invalid user');
        }

        $token = new Token();
        $token->setUser($user);
        $token->setReferrer($referrer);
        $token->setToken($tokenString);



        try {
            $this->em->persist($token);
            $this->em->flush();
        }catch(\Exception $e){
            throw new \Exception('The token already exists.');
        }

        $log = new Log();
        $log->setModelId($token->getId());
        $log->setModelName('PIM\\Token');
        $log->setUser($this->app['auth.user']);
        $log->setMode(Log::INSERTED);
        // The hash, not the token — see deleteToken() (013-001-0004).
        $log->setModelLabel($token->getToken());
        $this->em->persist($log);
        $this->em->flush();

        $userData = array(
            'id' => $token->getUser()->getId(),
            'alias' => $token->getUser()->getAlias(),
            'active' => $token->getUser()->getIsActive()
        );

        // Here the PLAIN TEXT: it is the value the caller brought along themselves, and the
        // only point in time at which it can be returned.
        return array('id' => $token->getId(), 'token' => $token->getPlaintext(), 'referrer' => $token->getReferrer(), 'user' => $userData);
    }
}