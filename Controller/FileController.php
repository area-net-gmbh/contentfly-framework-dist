<?php
namespace Areanet\PIM\Controller;
use Areanet\PIM\Classes\Config;
use Areanet\PIM\Classes\Controller\BaseController;
use Areanet\PIM\Classes\Event;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Exceptions\FileNotFoundException;
use Areanet\PIM\Classes\File\Backend;
use Areanet\PIM\Classes\File\Processing;
use Areanet\PIM\Classes\File\UploadValidator;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Classes\Permission;
use Areanet\PIM\Entity\File;
use Areanet\PIM\Entity\Log;
use DateTime;
use DirectoryIterator;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Exception;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;


class FileController extends BaseController
{
    /**
     * @apiVersion 2.0.0
     * @api {post} /file/upload upload
     * @apiName Upload
     * @apiGroup File
     * @apiHeader {String} Authorization <code>Bearer &lt;token&gt;</code> — the token from /auth/login. The legacy header <code>appcms-token</code> is still accepted.
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiDescription Regular POST upload of files
     *
     * @apiSuccessExample {json} Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "data": {
     *         "id": "aa433220-9b62-4e77-93c1-8c3e8c52e7ec",
     *         "created": {
     *           "LOCAL_TIME": "21.09.2026 08:09",
     *           "LOCAL": "21.09.2026",
     *           "ISO8601": "2026-09-21T08:09:28+0200",
     *           "TIMESTAMP": 1789970968
     *         },
     *         "modified": {
     *           "LOCAL_TIME": "21.09.2026 08:09",
     *           "LOCAL": "21.09.2026",
     *           "ISO8601": "2026-09-21T08:09:28+0200",
     *           "TIMESTAMP": 1789970968
     *         },
     *         "name": "sample.jpg",
     *         "folder": null,
     *         "title": null,
     *         "altText": null,
     *         "type": "image/jpeg",
     *         "hash": "401b30e3b8b5d629635a5c613cdb7919",
     *         "size": 123456,
     *         "width": 1920,
     *         "height": 1080
     *       },
     *       "errors": null,
     *       "meta": {
     *         "ts": "2026-09-21 08:09:28",
     *         "version": "2.1.0",
     *         "projectVersion": "1.0.0",
     *         "hash": "4f3e247083bdb20bc3c11be6a28f9b65"
     *       }
     *     }
     */
    public function uploadAction(Request $request): JsonResponse
    {


        if(!Permission::isWritable($this->app['auth.user'], 'PIM\\File')){
            throw new AccessDeniedHttpException("Access to PIM\\File denied.");
        }

        $event = new Event();
        $event->setParam('request', $request);
        $event->setParam('user',    $this->app['auth.user']);
        $event->setParam('app',     $this->app);
        $this->app['dispatcher']->dispatch($event, 'pim.file.before.upload');

        $file   = $request->files->get('file');

        /*
         * CHECKED BEFORE ANYTHING IS WRITTEN (000-000-0038).
         *
         * Until here the stored name was built from the client's name, extension included — and
         * `data/files/` is served straight from disk. An upload `probe-upload.php` was stored and
         * executed on request (`EXECUTED-42`, measured 2026-09-15). The validator rejects such a
         * name with 415 and builds the name that is stored; nothing below may fall back to the
         * client's name. See Classes/File/UploadValidator.
         */
        $validator = new UploadValidator();
        $upload    = $validator->validate($file instanceof UploadedFile ? $file : null);

        /*
         * The values are read ONCE here, not 16 times in the body.
         *
         * Up to Symfony 3.4, files->get() returned the raw $_FILES array, and the body
         * accessed it via $uploadName. That worked by accident: PHP 8.1 adds the key
         * `full_path` to $_FILES, the detection in HttpFoundation 3.4 (FileBag::$fileKeys)
         * compares the keys exactly, fails on it and passed the array through. Since
         * 006-002-0003 (Symfony 4.4) an UploadedFile comes back, and the array access became
         * a fatal error — exactly where 008-002 had predicted it.
         *
         * The mapping deliberately follows the old $_FILES entry word for word, so that the
         * behaviour does not change along the way:
         *
         *   getPathname()            <- $_FILES['tmp_name']  path of the temporary upload file
         *   getSize()                <- $_FILES['size']      size
         *
         * NAME AND TYPE COME FROM THE VALIDATOR SINCE 000-000-0038, no longer from
         * getClientOriginalName() and getClientMimeType() directly. The type stays the client's
         * statement as long as no whitelist is configured — it only selects the image processor;
         * with FILE_ALLOWED_TYPES it is detected from the content.
         *
         * These methods exist in HttpFoundation 4.4 just as in 7.4. After this, the body does
         * not see any Symfony at all, so it is not once again the spot that breaks during the
         * kernel swap (Epic 009).
         */
        $uploadTmpPath = $file->getPathname();
        $uploadType    = $upload['type'];
        $uploadSize    = $file->getSize();

        if(($request->request->all()["id"] ?? null)){

            $fileObject = $this->em->getRepository('Areanet\PIM\Entity\File')->find(($request->request->all()["id"] ?? null));

            if (!$fileObject) {
                $fileObject = new File();

                $metadata = $this->em->getClassMetaData(get_class($fileObject));
                $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
                if(Config\Adapter::getConfig()->DB_GUID_STRATEGY) $metadata->setIdGenerator(new AssignedGenerator());
                $fileObject->setId(($request->request->all()["id"] ?? null));

                $filename       = $upload['name'];
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

                // A name stored before 000-000-0038 may be one the web server executes. It does
                // not survive a re-upload: the record gets the checked name, the old file goes.
                if(!$validator->isAcceptableName((string) $filename)){
                    $previous = (string) $filename;
                    $filename = $upload['name'];
                    $fileObject->setName($filename);

                    $previousPath = Backend::getInstance()->getPath($fileObject).'/'.basename($previous);
                    if($previous !== '' && is_file($previousPath)){
                        unlink($previousPath);
                    }
                }
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

            $filename       = $upload['name'];

            if (!$fileObject) {


                $fileObject = new File();


                if(($request->request->all()["folder"] ?? null)){
                    $folder = $this->em->getRepository('Areanet\PIM\Entity\Folder')->find(($request->request->all()["folder"] ?? null));
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
                try {
                    $processor->execute($backend, $fileObject);
                } catch (ContentflyException $e) {
                    // Nothing of a rejected upload stays behind (000-000-0068).
                    $this->discardUpload($fileObject, $log);
                    throw $e;
                }


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
        $this->app['dispatcher']->dispatch($event, 'pim.file.after.upload');


        // 011-001-0004: `message` is gone — the 200 says it, and the file object is the payload.
        return $this->renderResponse($fileObject->toValueObject($this->app, 'PIM\\File'));
    }

    /**
     * @apiVersion 2.0.0
     * @api {get} /file/get/:id/[:size]/[:variant]/[:alias] get
     * @apiName Get
     * @apiGroup File
     * @apiParam {string} id ID or file name
     * @apiParam {string} size=null Optional: alias of the desired thumbnail size, must be defined accordingly in the PIM backend or as a PIM default ("pim_list", "pim_small")
     * @apiParam {string} variant=null Optional: 1x = 1/3 size of the original image / 2x = 2/3 size of the original image / 3x = original image
     * @apiParam {string} alias=null Optional: arbitrary file name for SEO (the file is loaded solely via the ID)
     * @apiExample {curl} Query by ID
     *     /file/get/12
     * @apiExample {curl} ID and file name
     *     /file/get/12/sample.jpg
     * @apiExample {curl} ID and size
     *     /file/get/12/s-large
     * @apiExample {curl} Thumbnails by ID and file name
     *     /file/get/12/small/sample.jpg
     * @apiExample {curl} Thumbnails by ID, file name and responsive
     *     /file/get/12/small/3x/sample.jpg (original image)
     * @apiExample {curl} Thumbnails by ID, file name and responsive
     *     /file/get/12/small/2x/sample.jpg (2/3 size of the original image)
     * @apiExample {curl} Thumbnails by ID, file name and responsive
     *     /file/get/12/small/1x/sample.jpg (1/3 size of the original image)
     *
     * @apiDescription Download/display of files; the call can be made using the following combinations
     *
     * - /file/get/ID
     * - /file/get/ID/ALIAS
     * - /file/get/ID/s-SIZE
     * - /file/get/ID/s-SIZE/ALIAS
     * - /file/get/ID/SIZE/ALIAS
     * - /file/get/ID/SIZE/VARIANT/ALIAS
     * - /file/get/ID/s-SIZE/VARIANT/ALIAS
     *
     * The parameter ALIAS (e.g. an arbitrary file name) can be set freely for SEO purposes and has no influence on the query of the corresponding object. Only the ID matters for the query.
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
        $this->app['dispatcher']->dispatch($event, 'pim.file.before.get');

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
        $this->app['dispatcher']->dispatch($event, 'pim.file.before.send');

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

            /**
             * A StreamedResponse directly (009-001-0004).
             *
             * Previously `$this->app->stream(...)` — a Silex convenience whose entire body
             * reads `return new StreamedResponse($callback, $status, $headers)`. The detour
             * via the application gained nothing and would have given 009-002 one more
             * method to rebuild.
             *
             * THAT STREAMING HAPPENS HERE IS TIED TO bootstrap.php: there, deliberately NO
             * ob_start() is set (000-000-0018). A StreamedResponse writes its body when it is
             * sent, not when it is created; an output buffer on top of it would pull the
             * delivery of large files into memory. Whoever changes the one has to take the
             * other into account.
             */
            return new StreamedResponse($stream, 200, array(
                'Content-Type'   => $mimeType,
                'Content-length' => filesize($fileName),
                'Cache-Control' => 'max-age='.Config\Adapter::getConfig()->FILE_CACHE_LIFETIME.', public',
                'Pragma' => 'public',
                'ETag' => $etagFile,
                'Last-Modified' => $server_last_modified,
                'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + Config\Adapter::getConfig()->FILE_CACHE_LIFETIME)
            ));
        }else{

            // WEB_ROOT is the mount point from the configuration, default '/'. Up to
            // 000-000-0006, bootstrap-web.php overwrote it from $_SERVER['PHP_SELF']; the
            // redirect then pointed nowhere everywhere except behind the bundled .htaccess.
            $redirectUri = Config\Adapter::getConfig()->WEB_ROOT."data/files/$id/".basename($fileName);

            // A RedirectResponse directly (009-001-0004) — $this->app->redirect() did nothing
            // other than build exactly this.
            return new RedirectResponse($redirectUri, 301);
        }

    }
    
    public function overwriteAction(Request $request): JsonResponse
    {
        $sourceId   = ($request->request->all()["sourceId"] ?? null);
        $destId     = ($request->request->all()["destId"] ?? null);

        if(!($permission = Permission::isWritable($this->app['auth.user'], 'PIM\\File'))){
            throw new AccessDeniedHttpException("Access to PIM\\File denied.");
        }

        if(!$sourceId || !$destId){
            throw new FileNotFoundException(Messages::contentfly_general_missing_params);
        }
        

        $fileSource = $this->em->getRepository('Areanet\PIM\Entity\File')->find($sourceId);
        $fileDest   = $this->em->getRepository('Areanet\PIM\Entity\File')->find($destId);

        if(!$fileSource || !$fileDest){
            throw new FileNotFoundException(Messages::contentfly_general_not_found);
        }

        /*
         * BOTH files must be writable for this user (000-000-0060). Only the right on PIM\File
         * was checked here: with OWN a user replaced the content of any file carrying the same
         * name — and made a foreign source disappear, because it is moved, not copied.
         */
        $this->assertFileWritable($permission, $fileDest);
        $this->assertFileWritable($permission, $fileSource);

        if($fileSource->getName() != $fileDest->getName()){
            throw new FileNotFoundException(Messages::contentfly_general_not_found);
        }

        // Both names are stored ones and equal. One from before 000-000-0038 may be executable;
        // overwrite moves files under that name, so it is refused rather than carried along.
        if(!(new UploadValidator())->isAcceptableName((string) $fileDest->getName())){
            throw new ContentflyException(Messages::contentfly_file_invalid_type, $fileDest->getName(), 415);
        }

        $backend    = Backend::getInstance();

        //Delete old data
        $pathDest   = $backend->getPath($fileDest);
        foreach (new DirectoryIterator($pathDest) as $fileInfo) {
            if ($fileInfo->isDot() || !$fileInfo->isFile()) continue;
            unlink($fileInfo->getPathname());
        }

        //Move new data
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

        // 011-001-0004: the two ids ARE what this endpoint has to say; `message` said it twice.
        return $this->renderResponse(array('sourceId' => $sourceId, 'destId' => $destId));
    }

    /**
     * Narrows the write right on PIM\File to one file — the same rule Api::doUpdate() applies:
     * OWN reaches files the user created or is listed in `users`, GROUP additionally those
     * listed for the user's group.
     */
    /**
     * Removes a new upload that the image processor refused: the record, its log row and the
     * directory with the original and any thumbnails written before the failure (000-000-0068).
     *
     * Only for a NEW record. Re-uploading onto an existing id is not rolled back — the header
     * checks in UploadValidator run before that path writes anything, and only a file with a
     * valid header and a broken body can still fail here.
     */
    private function discardUpload(File $fileObject, Log $log): void
    {
        $directory = Backend::getInstance()->getPath($fileObject);

        $this->em->remove($log);
        $this->em->remove($fileObject);
        $this->em->flush();

        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isFile()) {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }

    private function assertFileWritable(int $permission, File $file): void
    {
        $user = $this->app['auth.user'];

        if($permission == \Areanet\PIM\Entity\Permission::OWN && $file->getUserCreated() != $user && !$file->hasUserId($user->getId())){
            throw new AccessDeniedHttpException("Access to PIM\\File::{$file->getId()} denied.");
        }

        if($permission == \Areanet\PIM\Entity\Permission::GROUP && $file->getUserCreated() != $user){
            $group = $user->getGroup();
            if(!($group && $file->hasGroupId($group->getId()))){
                throw new AccessDeniedHttpException("Access to PIM\\File::{$file->getId()} denied.");
            }
        }
    }
}
