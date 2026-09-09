<?php
namespace Areanet\PIM\Controller;
use Areanet\PIM\Classes\Config;
use Areanet\PIM\Classes\Controller\BaseController;
use Areanet\PIM\Classes\Event;
use Areanet\PIM\Classes\Exceptions\FileNotFoundException;
use Areanet\PIM\Classes\File\Backend;
use Areanet\PIM\Classes\File\Processing;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Classes\Permission;
use Areanet\PIM\Entity\File;
use Areanet\PIM\Entity\Log;
use DateTime;
use DirectoryIterator;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;


class FileController extends BaseController
{
    /**
     * @apiVersion 1.3.0
     * @api {post} /file/upload upload
     * @apiName Upload
     * @apiGroup File
     * @apiHeader {String} X-Token Acces-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiDescription Normaler POST-Upload von Dateien
     *
     */
    public function uploadAction(Request $request): JsonResponse
    {


        if(!Permission::isWritable($this->app['auth.user'], 'PIM\\File')){
            throw new AccessDeniedHttpException("Zugriff auf PIM\\File verweigert.");
        }

        $event = new Event();
        $event->setParam('request', $request);
        $event->setParam('user',    $this->app['auth.user']);
        $event->setParam('app',     $this->app);
        $this->app['dispatcher']->dispatch('pim.file.before.upload', $event);

        $file   = $request->files->get('file');

        /*
         * Die vier Werte werden EINMAL hier gelesen, nicht 16-mal im Rumpf.
         *
         * Bis Symfony 3.4 kam aus files->get() das rohe $_FILES-Array, und der Rumpf griff
         * mit $uploadName darauf zu. Das funktionierte zufaellig: PHP 8.1 ergaenzt $_FILES
         * um den Schluessel `full_path`, die Erkennung in HttpFoundation 3.4
         * (FileBag::$fileKeys) vergleicht die Schluessel exakt, scheitert daran und reichte
         * das Array durch. Seit 006-002-0003 (Symfony 4.4) kommt ein UploadedFile, und der
         * Array-Zugriff wurde zum Fatal Error — genau dort, wo 008-002 es vorhergesagt hatte.
         *
         * Die Zuordnung ist bewusst wortgetreu zum alten $_FILES-Eintrag, damit sich das
         * Verhalten nicht nebenbei aendert:
         *
         *   getClientOriginalName()  <- $_FILES['name']      vom Client gemeldeter Name
         *   getPathname()            <- $_FILES['tmp_name']  Pfad der Upload-Temporaerdatei
         *   getClientMimeType()      <- $_FILES['type']      vom Client gemeldeter Typ
         *   getSize()                <- $_FILES['size']      Groesse
         *
         * getClientMimeType() und NICHT getMimeType(): Letzteres raet den Typ aus dem Inhalt
         * und lieferte damit etwas anderes als bisher. Was hier gebraucht wird, ist die
         * Angabe des Clients — dieselbe wie vorher.
         *
         * Die Methoden gibt es in HttpFoundation 4.4 wie in 7.4. Der Rumpf sieht danach
         * ueberhaupt kein Symfony mehr, ist also beim Kernel-Tausch (Epic 009) nicht wieder
         * die Stelle, die bricht.
         */
        $uploadName    = $file->getClientOriginalName();
        $uploadTmpPath = $file->getPathname();
        $uploadType    = $file->getClientMimeType();
        $uploadSize    = $file->getSize();

        if($request->get("id")){

            $fileObject = $this->em->getRepository('Areanet\PIM\Entity\File')->find($request->get("id"));

            if (!$fileObject) {
                $fileObject = new File();

                $metadata = $this->em->getClassMetaData(get_class($fileObject));
                $metadata->setIdGeneratorType(ClassMetadataInfo::GENERATOR_TYPE_NONE);
                if(Config\Adapter::getConfig()->DB_GUID_STRATEGY) $metadata->setIdGenerator(new AssignedGenerator());
                $fileObject->setId($request->get("id"));

                $extension      = pathinfo($uploadName, PATHINFO_EXTENSION);
                $baseFilename   = pathinfo($uploadName, PATHINFO_FILENAME);
                $filename       = $this->sanitizeFileName($baseFilename) . "." . $extension;
                $fileObject->setName($filename);

                //AUDIT
                $log = new Log();
                $log->setModelName('PIM\File');
                $log->setUser($this->app['auth.user']);
                $log->setModelId($fileObject->getId());
                $log->setMode(Log::INSERTED);
                $this->em->persist($log);
                $this->em->flush();
            }else{
                $filename = $fileObject->getName();
                $log = new Log();
                $log->setModelName('PIM\File');
                $log->setUser($this->app['auth.user']);
                $log->setModelId($fileObject->getId());
                $log->setMode(Log::UPDATED);
                $this->em->persist($log);
            }

            $hash = md5_file($uploadTmpPath);

            $width  = null;
            $height = null;
            try{
                list($width, $height) = getimagesize($uploadTmpPath);
            }catch(Exception $e){

            }

            if($width){
                $fileObject->setWidth($width);
            }
            if($height){
                $fileObject->setHeight($height);
            }

            $fileObject->setType($uploadType);
            $fileObject->setSize($uploadSize);
            $fileObject->setUserCreated($this->app['auth.user']);
            $fileObject->setUser($this->app['auth.user']);
            $fileObject->setHash($hash);

            $this->em->persist($fileObject);
            $this->em->flush();

            $backend = Backend::getInstance();
            $dir = $backend->getPath($fileObject);
            move_uploaded_file($uploadTmpPath, $dir . '/' . $filename);

            $processor = Processing::getInstance($uploadType);
            $processor->execute($backend, $fileObject);

        }else {
            $hash = md5_file($uploadTmpPath);

            $fileObject = null;
            if(Config\Adapter::getConfig()->FILE_HASH_MUST_UNIQUE){
                $fileObject = $this->em->getRepository('Areanet\PIM\Entity\File')->findOneBy(array('hash' => $hash));
            }

            try{
                list($width, $height) = getimagesize($uploadTmpPath);
            }catch(Exception $e){
                $width  = null;
                $height = null;
            }

            $extension      = pathinfo($uploadName, PATHINFO_EXTENSION);
            $baseFilename   = pathinfo($uploadName, PATHINFO_FILENAME);
            $filename       = $this->sanitizeFileName($baseFilename) . "." . $extension;

            if (!$fileObject) {


                $fileObject = new File();


                if($request->get("folder")){
                    $folder = $this->em->getRepository('Areanet\PIM\Entity\Folder')->find($request->get("folder"));
                    if($folder){
                        $fileObject->setFolder($folder);
                    }
                }

                if($width){
                    $fileObject->setWidth($width);
                }
                if($height){
                    $fileObject->setHeight($height);
                }

                $fileObject->setName($filename);
                $fileObject->setType($uploadType);
                $fileObject->setSize($uploadSize);
                $fileObject->setHash($hash);
                $fileObject->setUserCreated($this->app['auth.user']);
                $fileObject->setUser($this->app['auth.user']);
                $this->em->persist($fileObject);

                $this->em->flush();

                $backend = Backend::getInstance();
                $dir = $backend->getPath($fileObject);

                move_uploaded_file($uploadTmpPath, $dir . '/' . $filename);

                $log = new Log();
                $log->setModelName('PIM\File');
                $log->setUser($this->app['auth.user']);
                $log->setModelId($fileObject->getId());
                $log->setMode(Log::INSERTED);
                $this->em->persist($log);

                $this->em->flush();



                $processor = Processing::getInstance($uploadType);
                $processor->execute($backend, $fileObject);


            } else {

                $fileObject->setUser($this->app['auth.user']);

                if($width){
                    $fileObject->setWidth($width);
                }
                if($height){
                    $fileObject->setHeight($height);
                }

                $log = new Log();
                $log->setModelName('PIM\File');
                $log->setUser($this->app['auth.user']);
                $log->setModelId($fileObject->getId());
                $log->setMode(Log::UPDATED);
                $this->em->persist($log);

                $this->em->persist($fileObject);
                $this->em->flush();
            }
        }

        $event = new Event();
        $event->setParam('request', $request);
        $event->setParam('fileObject', $fileObject);
        $event->setParam('user',    $this->app['auth.user']);
        $event->setParam('app',     $this->app);
        $this->app['dispatcher']->dispatch('pim.file.after.upload', $event);


        return new JsonResponse(array('message' => 'File uploaded', 'data' => $fileObject->toValueObject($this->app, 'PIM\\File')));
    }

    /**
     * @apiVersion 1.3.0
     * @api {get} /file/get/:id/[:size]/[:variant]/[:alias] get
     * @apiName Get
     * @apiGroup File
     * @apiParam {string} id ID oder Dateiname
     * @apiParam {string} size=null Optional: Alias der gewünschten Thumbnail-Größe, muss im PIM-Backend oder als PIM-Standard ("pim_list", "pim_small") entsprechend definiert sein
     * @apiParam {string} variant=null Optional: 1x = 1/3 Größe von Originalbild / 2x = 2/3 Größe von Originalbild / 3x = Originalbild
     * @apiParam {string} alias=null Optional: Beliebiger Dateiname für SEO (Die Datei wird lediglich über die ID geladen)
     * @apiExample {curl} Abfrage anhand ID
     *     /file/get/12
     * @apiExample {curl} ID und Dateiname
     *     /file/get/12/sample.jpg
     * @apiExample {curl} ID und Größe
     *     /file/get/12/s-large
     * @apiExample {curl} Thumbnails anhand ID und Dateiname
     *     /file/get/12/small/sample.jpg
     * @apiExample {curl} Thumbnails anhand ID, Dateiname und Responsive
     *     /file/get/12/small/3x/sample.jpg (Original-Bild)
     * @apiExample {curl} Thumbnails anhand ID, Dateiname und Responsive
     *     /file/get/12/small/2x/sample.jpg (2/3 Größe von Original-Bild)
     * @apiExample {curl} Thumbnails anhand ID, Dateiname und Responsive
     *     /file/get/12/small/1x/sample.jpg (1/3 Größe von Original-Bild)
     *
     * @apiDescription Download/Darstellung von Dateien, der Aufruf kann über folgende Kombinationen erfolgen
     *
     * - /file/get/ID
     * - /file/get/ID/ALIAS
     * - /file/get/ID/s-SIZE
     * - /file/get/ID/s-SIZE/ALIAS
     * - /file/get/ID/SIZE/ALIAS
     * - /file/get/ID/SIZE/VARIANT/ALIAS
     * - /file/get/ID/s-SIZE/VARIANT/ALIAS
     *
     * Der Parameter ALIAS (z.B. beliebiger Dateiname) kann frei für SEO-Zwecke gesetzt werden und hat keinen Einfluss auf die Abfrage des entsprechenden Objektes. Für die Abfrage spielt lediglich die ID eine Rolle.
     */
    public function getAction($id, $alias = null, $size = null, $variant = null): RedirectResponse|StreamedResponse|Response
    {
        $fileObject = $this->em->getRepository('Areanet\PIM\Entity\File')->find($id);

        if(!$fileObject){
            throw new FileNotFoundException(Messages::contentfly_general_not_found);
        }

        $sizeObject = null;
        if($size){
            $sizeObject = $this->em->getRepository('Areanet\PIM\Entity\ThumbnailSetting')->findOneBy(array('alias' => $size));

            if(!$sizeObject){
                throw new FileNotFoundException(Messages::contentfly_general_filesize_not_found);
            }
        }

        $event = new Event();
        $event->setParam('id', $id);
        $event->setParam('fileObject', $fileObject);
        $event->setParam('sizeObject', $sizeObject);
        $event->setParam('app',     $this->app);
        $this->app['dispatcher']->dispatch('pim.file.before.get', $event);

        $mimeType   = $fileObject->getType();
        $backend    = Backend::getInstance();
        $fileUri    = $backend->getUri($fileObject, $sizeObject, $variant);

        $reExecute  =  false;

        $fileMTime = 0;
        $etagFile  = null;
        if(file_exists($fileUri)) {
            $fileMTime = filemtime($fileUri);
            $etagFile  = md5($fileUri . $fileMTime);
        }

        if($size){

            $sizeObject = $this->em->getRepository('Areanet\PIM\Entity\ThumbnailSetting')->findOneBy(array('alias' => $size));

            if($sizeObject->getForceJpeg()){
                $mimeType = 'image/jpeg';
            }

            $sizeTime = $sizeObject->getModified()->getTimestamp();
            if ($sizeTime > $fileMTime) {
                $reExecute = true;

            }
        }

        if(!file_exists($fileUri) || $reExecute){

            $processor = Processing::getInstance($fileObject->getType());
            if($processor instanceof Processing\Standard){
                throw new FileNotFoundException(Messages::contentfly_general_filesize_not_found);
            }else{

                $processor->execute($backend, $fileObject, $size, $variant);
            }

            $fileMTime = filemtime($fileUri);
            $etagFile  = md5($fileUri . $fileMTime);
        }

        $fileName   = $backend->getUri($fileObject, $sizeObject, $variant);
        if(!file_exists($fileName)){

            throw new FileNotFoundException(Messages::contentfly_general_not_found);
        }

        $client_etag =
            !empty($_SERVER['HTTP_IF_NONE_MATCH'])
                ?   trim($_SERVER['HTTP_IF_NONE_MATCH'])
                :   null
        ;
        $client_last_modified =
            !empty($_SERVER['HTTP_IF_MODIFIED_SINCE'])
                ?   trim($_SERVER['HTTP_IF_MODIFIED_SINCE'])
                :   null
        ;

        $server_last_modified   = gmdate('D, d M Y H:i:s', $fileMTime) . ' GMT';

        $matching_last_modified = $client_last_modified == $server_last_modified;
        $matching_etag          = $client_etag && str_contains($client_etag, $etagFile);

        if (($client_last_modified && $client_etag) ?  $matching_last_modified && $matching_etag : $matching_last_modified || $matching_etag){
            return new Response(null, 304, array('X-Status-Code' => 304, 'Cache-control' => 'max-age='.Config\Adapter::getConfig()->FILE_CACHE_LIFETIME.', public'));
        }

        $event = new Event();
        $event->setParam('id', $id);
        $event->setParam('fileObject', $fileObject);
        $event->setParam('sizeObject', $sizeObject);
        $event->setParam('fileName',   $fileName);
        $event->setParam('app',     $this->app);
        $this->app['dispatcher']->dispatch('pim.file.before.send', $event);

        if(Config\Adapter::getConfig()->APP_FILE_MODE == 'xsendfile') {
            $headers = array(
                'Pragma' => 'public',
                'Cache-Control' => 'max-age='.Config\Adapter::getConfig()->FILE_CACHE_LIFETIME.', public',
                'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + Config\Adapter::getConfig()->FILE_CACHE_LIFETIME),
                'Content-length' => filesize($fileName),
                'Content-type' => $mimeType,
                'Last-Modified' => $server_last_modified,
                'ETag' => $etagFile,
                'X-Sendfile' => $fileName
            );
            return new Response('', 200, $headers);
        }else if(Config\Adapter::getConfig()->APP_FILE_MODE == 'readfile') {

            $stream = function () use ($fileName) {
                readfile($fileName);
            };

            return $this->app->stream($stream, 200, array(
                'Content-Type'   => $mimeType,
                'Content-length' => filesize($fileName),
                'Cache-Control' => 'max-age='.Config\Adapter::getConfig()->FILE_CACHE_LIFETIME.', public',
                'Pragma' => 'public',
                'ETag' => $etagFile,
                'Last-Modified' => $server_last_modified,
                'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + Config\Adapter::getConfig()->FILE_CACHE_LIFETIME)
            ));
        }else{

            // WEB_ROOT ist der Mountpunkt aus der Konfiguration, Vorgabe '/'. Bis 000-000-0006
            // hat bootstrap-web.php ihn aus $_SERVER['PHP_SELF'] ueberschrieben; der Redirect
            // zeigte dann ueberall ausser hinter der mitgelieferten .htaccess ins Leere.
            $redirectUri = Config\Adapter::getConfig()->WEB_ROOT."data/files/$id/".basename($fileName);
            return $this->app->redirect($redirectUri, 301);
        }

    }
    
    public function overwriteAction(Request $request): JsonResponse
    {
        $sourceId   = $request->get("sourceId");
        $destId     = $request->get("destId");

        if(!Permission::isWritable($this->app['auth.user'], 'PIM\\File')){
            throw new AccessDeniedHttpException("Zugriff auf PIM\\File verweigert.");
        }

        if(!$sourceId || !$destId){
            throw new FileNotFoundException(Messages::contentfly_general_missing_params);
        }
        

        $fileSource = $this->em->getRepository('Areanet\PIM\Entity\File')->find($sourceId);
        $fileDest   = $this->em->getRepository('Areanet\PIM\Entity\File')->find($destId);

        if(!$fileSource || !$fileDest){
            throw new FileNotFoundException(Messages::contentfly_general_not_found);
        }

        if($fileSource->getName() != $fileDest->getName()){
            throw new FileNotFoundException(Messages::contentfly_general_not_found);
        }

        $backend    = Backend::getInstance();

        //Alte Daten löschen
        $pathDest   = $backend->getPath($fileDest);
        foreach (new DirectoryIterator($pathDest) as $fileInfo) {
            if ($fileInfo->isDot() || !$fileInfo->isFile()) continue;
            unlink($fileInfo->getPathname());
        }

        //Neue Daten verschieben
        $pathSource  = $backend->getPath($fileSource);

        foreach (new DirectoryIterator($pathSource) as $fileInfo) {
            if ($fileInfo->isDot() || !$fileInfo->isFile()) continue;
            $destName = $pathDest.'/'.$fileInfo->getBasename();
            rename($fileInfo->getPathname(), $destName);
        }

        @rmdir($pathSource);

        $this->em->remove($fileSource);

        $width  = null;
        $height = null;
        try{
            list($width, $height) = getimagesize($pathDest.'/'.$fileInfo->getBasename());
        }catch(Exception $e){

        }

        if($width){
            $fileDest->setWidth($width);
        }
        if($height){
            $fileDest->setHeight($height);
        }

        $now = new DateTime();
        $fileDest->setModified($now);
        $this->em->persist($fileDest);
        $this->em->flush();

        return new JsonResponse(array('message' => 'File overwritten', 'sourceId' => $sourceId, 'destId' => $destId));
    }


    protected function sanitizeFileName($string, $force_lowercase = true, $anal = false): array|false|string|null
    {
        $strip = array("~", "`", "!", "@", "#", "$", "%", "^", "&", "*", "(", ")", "=", "+", "[", "{", "]",
            "}", "\\", "|", ";", ":", "\"", "'", "&#8216;", "&#8217;", "&#8220;", "&#8221;", "&#8211;", "&#8212;",
            "â€”", "â€“", ",", "<", ".", ">", "/", "?");
        $clean = trim(str_replace($strip, "", strip_tags($string)));
        $clean = preg_replace('/\s+/', "-", $clean);
        $clean = ($anal) ? preg_replace("/[^a-zA-Z0-9]/", "", $clean) : $clean ;
        return ($force_lowercase) ?
            (function_exists('mb_strtolower')) ?
                mb_strtolower($clean, 'UTF-8') :
                strtolower($clean) :
            $clean;
    }
}
