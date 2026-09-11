<?php
namespace Areanet\PIM\Controller;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Controller\BaseController;
use Areanet\PIM\Entity\Folder;
use Areanet\PIM\Entity\Log;
use Areanet\PIM\Entity\ThumbnailSetting;
use Areanet\PIM\Entity\Token;
use Areanet\PIM\Entity\User;
use Custom\Entity\Ansprechpartner;
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
use Areanet\PIM\Classes\Kernel\Pfade;

class SystemController extends BaseController
{

    /**
     * @apiVersion 1.3.0
     * @api {post} /system/do do
     * @apiName Ausführen
     * @apiDescription Führt Systembefehle aus.
     * @apiGroup System
     * @apiHeader {String} X-Token Acces-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} method Auszuführende Methode
     * @apiParamExample {json} Schema-Cache leeren:
     *     {
     *      "method": "flushSchemaCache",
     *     }
     * @apiParamExample {json} Datenbank synchronisieren:
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
     * Die ueber `POST /system/do` aufrufbaren Methoden (000-000-0015).
     *
     * Ausgeschrieben statt abgeleitet: Wer eine Methode hinzufuegt, entscheidet damit auch,
     * ob sie ein Endpunkt sein soll.
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
         * ERLAUBNISLISTE STATT method_exists (000-000-0015).
         *
         * Das Tor war `method_exists($this, $method)` — und damit erreichbar, was immer der
         * Controller oder seine Basisklasse mitbringt:
         *
         *   doAction         ist public, besteht die Pruefung und ruft sich selbst auf. Die
         *                    Rekursion endet erst am memory_limit — mit dem Standardwert nach
         *                    etwa 0,2 s in einem Fatal Error, OHNE Limit gar nicht.
         *   setEM,           kommen aus BaseController, werden aufgerufen und scheitern erst
         *   __construct      an ihrer Typpruefung. Die Grenze zog die Signatur, nicht der
         *                    Endpunkt.
         *
         * Die Liste nennt jetzt, was aufgerufen werden darf. Sie ist bewusst ausgeschrieben
         * und nicht aus Reflection abgeleitet: Was hier steht, ist eine Entscheidung, keine
         * Eigenschaft der Klasse — sonst waere jede neue Methode automatisch ein Endpunkt.
         *
         * `method` fehlt, wenn der Aufrufer sie nicht mitschickt. Bis PHP 8.0 ergab
         * method_exists($this, null) still false, seit 8.1 ist es eine Deprecation
         * (000-000-0023).
         */
        if (!is_string($method) || !in_array($method, self::ERLAUBTE_METHODEN, true)) {
            throw new \Exception('Methode '.(is_string($method) ? $method : '').' nicht verfügbar.');
        }

        $date = new \DateTime();

        return new JsonResponse(array('method' => $method, 'datetime' => $date->format('Y-m-d H:i:s'),  'message' => $this->$method($request) ));
    }

    /**
     * Leert den Schema-Cache: die Datei und die beiden Doctrine-Caches.
     *
     * PSR-6 STATT doctrine/cache (010-002-0003). Hier stand
     * `getQueryCacheImpl()->deleteAll()`. Die `…Impl()`-Getter liefern heute noch etwas — ORM
     * 2.20 verpackt den PSR-6-Pool in `Doctrine\Common\Cache\Psr6\DoctrineProvider` —, aber
     * diese Bruecke liegt in `doctrine/cache`, und das Paket geht mit `010-002-0004`.
     *
     * DIE PRUEFUNG AUF `null` IST NICHT VORSORGLICH, SONDERN NOETIG. Der Cache wird im
     * Bootstrap nur eingerichtet, wenn `!APP_DEBUG && !APPCMS_CONSOLE` gilt; ohne ihn liefern
     * die Getter `null`. Bisher stand hier stattdessen `if(!APP_DEBUG)` — dieselbe Bedingung,
     * aber nur die halbe: An `APPCMS_CONSOLE` war nicht gedacht. Dass es gutging, lag daran,
     * dass diese Methode ueber HTTP gerufen wird und die Konstante dort nie gesetzt ist. Zwei
     * Bedingungen, die an verschiedenen Stellen stehen und sich zufaellig decken, sind eine
     * Verabredung auf Zeit; die Methode fragt jetzt selbst.
     */
    protected function flushSchemaCache(Request $request)
    {
        if(file_exists(Pfade::daten().'/cache/schema.cache')){
            unlink(Pfade::daten().'/cache/schema.cache');
        }

        $konfiguration = $this->app['orm.em']->getConfiguration();

        foreach (array($konfiguration->getQueryCache(), $konfiguration->getMetadataCache()) as $cache) {
            if ($cache !== null) {
                $cache->clear();
            }
        }

        return 'Schema-Cache wurde geleert!';
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

        return "Die Datenbank wurde erfolgreich aktualisiert.";
    }
    

    protected function deleteToken(Request $request)
    {
        $id =  ($request->request->all()['id'] ?? null);

        $token = $this->em->getRepository('Areanet\\PIM\\Entity\\Token')->find($id);
        if(!$token){
            throw new \Exception('Token ungültig');
        }

        $log = new Log();
        $log->setModelId($id);
        $log->setModelName('PIM\\Token');
        $log->setUser($this->app['auth.user']);
        $log->setMode(Log::DELETED);
        /*
         * DAS LABEL IST DER HASH, NICHT DER TOKEN (013-001-0004).
         *
         * Hier stand der Token im Klartext — und `pim_log` ist ein Protokoll, das laenger lebt
         * als die Sitzung, die es beschreibt. Ein Dump des Logs uebergab damit dieselben
         * Sitzungen wie ein Dump der Tokentabelle. `getToken()` liefert seit diesem Task den
         * Hash; als Kennzeichen im Protokoll taugt er genauso, verwenden kann ihn niemand.
         */
        $log->setModelLabel($token->getToken());

        $this->em->remove($token);
        $this->em->persist($log);
        $this->em->flush();

        return true;
    }

    protected function generateToken(Request $request)
    {
        // `random_bytes()` statt `openssl_random_pseudo_bytes()` (013-001-0004): Die zweite
        // meldet ueber einen Ausgabeparameter, ob das Ergebnis kryptographisch stark ist —
        // niemand hat ihn je gelesen. `random_bytes()` liefert starke Bytes oder wirft.
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
             * `token` ist seit 013-001-0004 der HASH.
             *
             * Der Token selbst laesst sich nicht mehr nachschlagen — auch nicht vom Betreiber.
             * Das Feld bleibt trotzdem stehen: Es benennt die Zeile eindeutig, und wer einen
             * Token in der Hand haelt, kann ihn selbst hashen und so herausfinden, welcher
             * Eintrag dazugehoert. Ein Client, der den Wert versehentlich als Token vorzeigt,
             * bekommt eine 401 — er faellt zu, nicht auf.
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
            throw new \Exception('Token und/oder Referrer ungültig');
        }

        $user = $this->em->getRepository('Areanet\\PIM\\Entity\\User')->find($userId);
        if(!$user){
            throw new \Exception('Benutzer ungültig');
        }

        $token = new Token();
        $token->setUser($user);
        $token->setReferrer($referrer);
        $token->setToken($tokenString);



        try {
            $this->em->persist($token);
            $this->em->flush();
        }catch(\Exception $e){
            throw new \Exception('Der Token ist bereits vorhanden.');
        }

        $log = new Log();
        $log->setModelId($token->getId());
        $log->setModelName('PIM\\Token');
        $log->setUser($this->app['auth.user']);
        $log->setMode(Log::INSERTED);
        // Der Hash, nicht der Token — siehe deleteToken() (013-001-0004).
        $log->setModelLabel($token->getToken());
        $this->em->persist($log);
        $this->em->flush();

        $userData = array(
            'id' => $token->getUser()->getId(),
            'alias' => $token->getUser()->getAlias(),
            'active' => $token->getUser()->getIsActive()
        );

        // Hier der KLARTEXT: Es ist der Wert, den der Aufrufer selbst mitgebracht hat, und der
        // einzige Zeitpunkt, an dem er zurueckgegeben werden kann.
        return array('id' => $token->getId(), 'token' => $token->getKlartext(), 'referrer' => $token->getReferrer(), 'user' => $userData);
    }
}