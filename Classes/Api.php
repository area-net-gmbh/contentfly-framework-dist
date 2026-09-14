<?php
namespace Areanet\PIM\Classes;


use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Kernel\Paths;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Exceptions\ContentflyI18NException;
use Areanet\PIM\Classes\File\Backend;
use Areanet\PIM\Entity\Base;
use Areanet\PIM\Entity\BaseI18n;
use Areanet\PIM\Entity\BaseI18nSortable;
use Areanet\PIM\Entity\BaseI18nTree;
use Areanet\PIM\Entity\BaseSortable;
use Areanet\PIM\Entity\BaseTree;
use Areanet\PIM\Entity\File;
use Areanet\PIM\Entity\Log;
use Areanet\PIM\Entity\User;
use DateTime;
use DirectoryIterator;
use Areanet\PIM\Classes\Metadaten\Metadatenleser;
use Doctrine\Common\Persistence\Mapping\MappingException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMException;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Exception;
use Ramsey\Uuid\Uuid;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use stdClass;
use Symfony\Component\HttpFoundation\JsonResponse;


class Api
{
    protected array $_MIMETYPES = array(
        'images' => array('image/jpeg', 'image/png', 'image/gif'),
        'pdf' => array('application/pdf')
    );

    /** @var Application $app */
    protected Application $app;

    /** @var EntityManager $em */
    protected mixed $em;

    /** @var Connection $database */
    protected mixed $database;

    /** @var  @var Request $request */
    protected mixed $request;

    public function __construct($app, $request = null, ?User $user = null)
    {
        $this->app              = $app;
        $this->em               = $app['orm.em'];
        $this->database         = $app['database'];

        if($user){
            $this->app['auth.user'] = $user;
        }else{
            $this->app['auth.user'] = isset($this->app['auth.user']) ? $this->app['auth.user'] : null;
        }

        $this->request          = $request;
    }

    /**
     * @throws ContentflyException
     * @throws ORMException
     * @throws ContentflyI18NException
     * @throws OptimisticLockException
     * @throws MappingException
     */
    public function doDelete($entityName, $id, $lang = null){
        $schema = $this->app['schema'];

        $helper             = new Helper();
        $entityFullName     = $helper->getFullEntityName($entityName);
        $entityShortName    = $helper->getShortEntityName($entityName);

        if(!isset($schema[$entityShortName])){
            throw new ContentflyException(Messages::contentfly_general_unknown_entity, $entityShortName, Messages::contentfly_status_not_found);
        }

        $object = $this->getSingle($entityShortName, $id, null, $lang, true);

        $i18n   = $schema[$entityShortName]['settings']['i18n'];

        if(!$object){
            throw new ContentflyException(Messages::contentfly_general_not_found, $entityShortName, Messages::contentfly_status_not_found);
        }

        //Berechtigungen prüfen
        if(!($permission = Permission::isDeletable($this->app['auth.user'], $entityShortName))){
            throw new ContentflyException(Messages::contentfly_general_access_denied, $entityShortName, Messages::contentfly_status_access_denied);
        }

        if($permission == \Areanet\PIM\Entity\Permission::OWN && ($object->getUserCreated() != $this->app['auth.user'] && !$object->hasUserId($this->app['auth.user']->getId())) ){
            throw new ContentflyException(Messages::contentfly_general_access_denied, "$entityShortName::$id", Messages::contentfly_status_access_denied);
        }

        if($permission == \Areanet\PIM\Entity\Permission::GROUP){
            if($object->getUserCreated() != $this->app['auth.user']){
                $group = $this->app['auth.user']->getGroup();
                if(!($group && $object->hasGroupId($group->getId()))){
                    throw new ContentflyException(Messages::contentfly_general_access_denied, "$entityShortName::$id", Messages::contentfly_status_access_denied);
                }
            }
        }

        if(!I18nPermission::isWritable($this->app, $entityShortName, $lang)){
            throw new ContentflyI18NException(Messages::contentfly_i18n_permission_denied, $entityShortName, $lang);
        }

        if($entityShortName == 'PIM\\User'){

            if($object->getAlias() == 'admin'){
                throw new ContentflyException(Messages::contentfly_general_admin_not_deletable);
            }

        }

        //Prüfen, ob für Subsprachen bereits Übersetzungen bestehen
        /*if($i18n){
            $mainLang = is_array(Adapter::getConfig()->APP_LANGUAGES) ? Adapter::getConfig()->APP_LANGUAGES[0] : null;

            if($object->getLang() != $mainLang){

                // executeStatement() statt exec() (009-005-0002). exec() gibt es in DBAL 3
                // noch, aber als @deprecated — und Epic 009 baut deprecation-frei.
                $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0;');

                //$query = $this->em->createQuery("SELECT COUNT(e) FROM $entityFullName e WHERE e.id = :id");
                //$query->setParameter('id', $object->getId());

                //if($query->getSingleScalarResult() > 1){
                //    throw new ContentflyI18NException(Messages::contentfly_i18n_translations_exists, $entityShortName, $mainLang);
                //}
            }
        }*/

        //Baumstruktur aktualisieren
        if($schema[$entityShortName]['settings']['type'] == 'tree') {
            $subObjects = $this->em->getRepository($entityFullName)->findBy(array('treeParent' => $object->getId()));
            if($subObjects){
                foreach($subObjects as $subObject){
                    $this->doDelete($entityShortName, $subObject->getId(), $this->app);
                }
            }
        }

        //Dateien löschen
        //todo: Löschen von Datein aus API auslagern
        if($entityShortName == 'PIM\\File') {
            $backend    = Backend::getInstance();

            $path   = $backend->getPath($object);
            foreach (new DirectoryIterator($path) as $fileInfo) {
                if ($fileInfo->isDot() || !$fileInfo->isFile()) continue;
                unlink($fileInfo->getPathname());
            }
            @rmdir($path);
        }

        //Protokollierung
        $schema = $this->app['schema'];

        $log = new Log();

        $log->setModelId($object->getId());
        $log->setModelName($entityShortName);
        $log->setUserCreated($this->app['auth.user']);
        $log->setMode(Log::DELETED);

        if($schema[$entityShortName]['settings']['labelProperty']){
            try {
                $labelGetter = 'get' . ucfirst($schema[$entityShortName]['settings']['labelProperty']);
                $label = $object->$labelGetter();
                $log->setModelLabel($label);
            }catch(Exception){

            }

        }

        $this->em->persist($log);
        $this->em->flush();


        //OneJoins löschen
        foreach($schema[$entityShortName]['properties'] as $property => $propertyConfig){
            if($propertyConfig['type'] == 'onejoin'){
                $getterJoinedEntity = 'get'.ucfirst($property);
                $joinedEntity       = $object->$getterJoinedEntity();
                if($joinedEntity) $this->em->remove($joinedEntity);
            }
        }

        if($i18n){
            $query = $this->em->createQuery("DELETE FROM $entityFullName e WHERE e.id = :id AND NOT e.lang = :lang");
            $query->setParameter('id', $object->getId());
            $query->setParameter('lang', $object->getLang());
            $query->execute();
        }

        //Objekt löschen
        $this->em->remove($object);
        $this->em->flush();


        return $object;
    }

    /**
     * @param $entityName
     * @param $data
     * @param null $lang
     * @return Base|BaseI18n|mixed|object|null
     * @throws ContentflyException
     * @throws ContentflyI18NException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     * @throws MappingException
     */
    public function doInsert($entityName, $data, $lang = null): mixed
    {
        $schema  = $this->app['schema'];

        $helper             = new Helper();
        $entityFullName     = $helper->getFullEntityName($entityName);
        $entityShortName    = $helper->getShortEntityName($entityName);

        if(!isset($schema[$entityShortName])){
            throw new ContentflyException(Messages::contentfly_general_unknown_entity, $entityShortName, Messages::contentfly_status_not_found);
        }

        if(!Permission::isWritable($this->app['auth.user'], $entityShortName)){
            throw new ContentflyException(Messages::contentfly_general_permission_denied, $entityShortName, Messages::contentfly_status_access_denied);
        }

        if(I18nPermission::isOnlyReadable($this->app, $entityShortName, $lang)){
            throw new ContentflyI18NException(Messages::contentfly_i18n_permission_denied, $entityShortName, $lang);
        }

        $object  = new $entityFullName();

        $i18nObjects    = array();
        $i18nProperties = array();
        if($schema[$entityShortName]['settings']['i18n'] && isset($data['id'])){
            $tableName   = $schema[$entityShortName]['settings']['dbname'];
            $query       = $this->database->executeQuery("SELECT id, lang FROM $tableName WHERE id = ? AND NOT lang = ? ", array($data['id'], $lang));

            foreach($query->fetchAllAssociative() as $i18nObject){
                if(I18nPermission::isOnlyReadable($this->app, $entityShortName, $i18nObject['lang'])){
                    continue;
                }
                $i18nObjects[] = $i18nObject;
            }

        }

        foreach($data as $property => $value){
            if(!isset($schema[$entityShortName]['properties'][$property])){
                throw new ContentflyException(Messages::contentfly_general_unknown_property, "$entityShortName::$property");
            }

            $type = $schema[$entityShortName]['properties'][$property]['type'];
            $typeObject = $this->app['typeManager']->getType($type);
            if(!$typeObject){
                throw new ContentflyException(Messages::contentfly_general_unknown_type_object, "$entityShortName::$property::$typeObject");
            }

            if($schema[$entityShortName]['properties'][$property]['unique']){
                $objectDuplicated = $this->em->getRepository($entityFullName)->findOneBy(array($property => $value));
                if($objectDuplicated){
                    /*
                     * EIN ECHTER FEHLER, VON PHPSTAN GEFUNDEN (009-003-0002).
                     *
                     * Hier stand `Messages::contentfly_general_record_already_exists`. Diese
                     * Konstante gibt es nicht — sie heisst `…_ressource_already_exists`. Die
                     * Zeile war damit kein Fehlerbericht, sondern ein Fatal:
                     *
                     *     Undefined constant …Messages::contentfly_general_record_already_exists
                     *
                     * Der Aufrufer bekam 500 statt 409. `ConstraintApiTest` hat das als
                     * Ist-Zustand festgehalten und `000-000-0006` zugeschrieben — die
                     * Zuschreibung war falsch, es lag nie an der Fehlerkette.
                     *
                     * Jetzt dieselbe Konstante und derselbe Statuscode wie in den drei
                     * anderen Faellen weiter unten in doUpdate().
                     */
                    throw new ContentflyException(Messages::contentfly_general_ressource_already_exists, "$property::$value", Messages::contentfly_status_ressource_already_exists);
                }
            }

            if($property == 'id' && !empty($value)){
                $metadata = $this->em->getClassMetaData(get_class($object));
                $metadata->setIdGeneratorType(ClassMetadata::GENERATOR_TYPE_NONE);
                if(Config\Adapter::getConfig()->DB_GUID_STRATEGY) $metadata->setIdGenerator(new AssignedGenerator());

            }

            if($type == 'onejoin'){
                unset($value['id']);
            }

            if(!empty($schema[$entityShortName]['properties'][$property]['i18n_universal'])){
                if(count($i18nObjects)){
                    $i18nProperties[$property] = $value;
                }
            }

            $typeObject->toDatabase($this, $object, $property, $value, $entityShortName, $schema, $this->app['auth.user'], $data, $lang);

        }

        if($object instanceof Base){
            $object->setUserCreated($this->app['auth.user']);
            $object->setUser($this->app['auth.user']);
        }

        if($object instanceof BaseI18n){
            if(empty($data['id'])){
                try {
                    $uuid = Uuid::uuid4();
                    $object->setId($uuid);
                } catch (Exception) {
                }
            }

            if(empty($lang)){
                throw new ContentflyException(Messages::contentfly_i18n_missing_lang_param, $entityShortName);
            }

            $object->setLang($lang);

            $mainLang = is_array(Adapter::getConfig()->APP_LANGUAGES) ? Adapter::getConfig()->APP_LANGUAGES[0] : null;
            if($lang != $mainLang && !empty($data['id'])){
                $mainLangObject = $this->getSingle($entityShortName, $data['id'], null, $mainLang, true, null, null, true);
                if($mainLangObject){
                    foreach($schema[$entityShortName]['properties'] as $property => $propertyConfig){
                        if(!empty($propertyConfig['i18n_universal']) && $propertyConfig['type'] != 'multijoin' && $propertyConfig['type'] != 'multifile' && empty($data[$property])){
                            $getter = 'get'.ucfirst($property);
                            $setter = 'set'.ucfirst($property);
                            $object->$setter($mainLangObject->$getter());
                        }
                    }
                }
            }

        }

        try {
            $this->em->persist($object);
            $this->em->flush();

            /**
             * Log insert actions
             */
            $log = new Log();

            $log->setModelId($object->getId());
            $log->setModelName($entityShortName);
            $log->setUserCreated($this->app['auth.user']);
            $log->setMode(Log::INSERTED);

            if($schema[$entityShortName]['settings']['labelProperty']){
                try {
                    $labelGetter = 'get'.ucfirst($schema[$entityShortName]['settings']['labelProperty']);
                    $label = $object->$labelGetter();
                    $log->setModelLabel($label);
                }catch(Exception){

                }

            }

            $this->em->persist($log);
            $this->em->flush();

        }catch(UniqueConstraintViolationException $e){
            if($entityShortName == 'PIM\User'){
                throw new ContentflyException(Messages::contentfly_general_user_already_exists, $data['alias']);
            }
            $uniqueObjectLoaded = false;

            foreach($schema[$entityShortName]['properties'] as $property => $propertySettings){

                if($propertySettings['unique']){
                    $object = $this->em->getRepository($entityFullName)->findOneBy(array($property => $data[$property]));
                    if(!$object){
                        throw new ContentflyException(Messages::contentfly_general_unknown_perror, "$entityShortName::$property (100)".$e->getMessage());
                    }
                    $uniqueObjectLoaded = true;
                    break;
                }
            }

            if(!$uniqueObjectLoaded){
                throw new ContentflyException(Messages::contentfly_general_unknown_perror, "$entityShortName::$property (200) ".$e->getMessage());
            }
        }catch(Exception $e){
            throw new ContentflyException($e->getMessage());
        }

        if(count($i18nProperties) && count($i18nObjects)) {
            foreach ($i18nObjects as $i18nObject) {
                $this->doUpdate($entityShortName, $i18nObject['id'], $i18nProperties, true, null, $i18nObject['lang'], true);
            }
        }
        //if(count($i18nProperties) && count($i18nObjects)) {
        //    return array('updateI18n' => true, 'object' => $object, 'i18nObjects' => $i18nObjects, 'i18nProperties' => $i18nProperties);

        return $object;

    }

    /**
     * @param string $entityName
     * @param string $id
     * @param array $data
     * @param bool|null $disableModifiedTime
     * @param null $currentUserPass
     * @param null $lang
     * @param bool $isUnviversalUpdate
     * @return array|null
     * @throws ContentflyException
     * @throws ContentflyI18NException
     * @throws MappingException
     * @throws NonUniqueResultException
     * @throws ORMException
     * @throws OptimisticLockException
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    public function doUpdate(string $entityName, string $id, array $data, bool|null $disableModifiedTime, $currentUserPass = null, $lang = null, bool $isUnviversalUpdate = false): ?array
    {
        $schema  = $this->app['schema'];

        $helper             = new Helper();
        $entityShortName    = $helper->getShortEntityName($entityName);

        if(!isset($schema[$entityShortName])){
            throw new ContentflyException(Messages::contentfly_general_unknown_entity, $entityShortName, Messages::contentfly_status_not_found);
        }

        $object = $this->getSingle($entityShortName, $id, null, $lang, true);

        if(!$object){
            throw new ContentflyException(Messages::contentfly_general_not_found, $entityShortName, Messages::contentfly_status_not_found);
        }

        if(!($permission = Permission::isWritable($this->app['auth.user'], $entityShortName))){
            throw new ContentflyException(Messages::contentfly_general_permission_denied, $entityShortName, Messages::contentfly_status_access_denied);
        }

        if($permission == \Areanet\PIM\Entity\Permission::OWN && ($object->getUserCreated() != $this->app['auth.user'] && !$object->hasUserId($this->app['auth.user']->getId()) && $object != $this->app['auth.user'])){
            throw new ContentflyException(Messages::contentfly_general_permission_denied, "$entityShortName::$id", Messages::contentfly_status_access_denied);
        }

        if($permission == \Areanet\PIM\Entity\Permission::GROUP){
            if($object->getUserCreated() != $this->app['auth.user']){
                $group = $this->app['auth.user']->getGroup();
                if(!($group && $object->hasGroupId($group->getId()))){
                    throw new ContentflyException(Messages::contentfly_general_permission_denied, "$entityShortName::$id", Messages::contentfly_status_access_denied);
                }
            }
        }

        if(I18nPermission::isOnlyReadable($this->app, $entityShortName, $lang)){
            throw new ContentflyI18NException(Messages::contentfly_i18n_permission_denied, $entityShortName, $lang);
        }

        if($object instanceof User && isset($data['pass']) && !$this->app['auth.user']->getIsAdmin()){
            if(!$this->app['auth.user']->isPass($currentUserPass)){
                throw new ContentflyException(Messages::contentfly_general_invalid_password, $this->app['auth.user']->getAlias());
            }
        }

        $i18nObjects    = array();
        $i18nProperties = array();
        if($schema[$entityShortName]['settings']['i18n'] && !$isUnviversalUpdate){
            $tableName   = $schema[$entityShortName]['settings']['dbname'];
            $query       = $this->database->executeQuery("SELECT id, lang FROM $tableName WHERE id = ? AND NOT lang = ? ", array($object->getId(), $object->getLang()));
            foreach($query->fetchAllAssociative() as $i18nObject){
                if(I18nPermission::isOnlyReadable($this->app, $entityShortName, $i18nObject['lang'])){
                    continue;
                }
                $i18nObjects[] = $i18nObject;
            }
        }

        $usersRemoved   = $helper->getUsersRemoved($object, $data);

        foreach($data as $property => $value){
            if($property == 'modified' || $property == 'created') continue;


            if(!isset($schema[$entityShortName]['properties'][$property])){
                throw new ContentflyException(Messages::contentfly_general_unknown_property, "$entityShortName::$property");
            }

            $type = $schema[$entityShortName]['properties'][$property]['type'];
            $typeObject =  $this->app['typeManager']->getType($type);
            if(!$typeObject){
                throw new ContentflyException(Messages::contentfly_general_unknown_type_object, "$entityShortName::$property::$typeObject");
            }

            if(!empty($schema[$entityShortName]['properties'][$property]['i18n_universal'])){
                if(count($i18nObjects)){
                    $i18nProperties[$property] = $value;
                }
            }

            $typeObject->toDatabase($this, $object, $property, $value, $entityShortName, $schema, $this->app['auth.user'], null, $lang);

        }

        foreach($schema[$entityShortName]['properties'] as $property => $propertyConfig){
            if($propertyConfig['type'] == 'onejoin'){
                $getterJoinedEntity = 'get'.ucfirst($property);
                $joinedEntity       = $object->$getterJoinedEntity();
                if($joinedEntity){
                    $joinedEntity->setUsers($object->getUsers(true));
                    $joinedEntity->setGroups($object->getGroups(true));
                    $joinedEntity->setUserCreated($object->getUserCreated());
                }
            }
        }

        $object->setModified(new DateTime());
        $object->setUser($this->app['auth.user']);

        try{
            if($disableModifiedTime){
                $object->doDisableModifiedTime(true);
            }

            $this->em->flush();

        }catch(UniqueConstraintViolationException){
            if($entityShortName == 'PIM\User'){
                throw new ContentflyException(Messages::contentfly_general_user_already_exists, $data['alias'],Messages::contentfly_status_ressource_already_exists);
            }elseif($entityShortName == 'PIM\File') {
                $existingFile = $this->em->getRepository('Areanet\PIM\Entity\File')->findOneBy(array('name' => $object->getName(), 'folder' => $object->getFolder()->getId()));
                throw new ContentflyException(Messages::contentfly_general_ressource_already_exists, $existingFile->getId(), Messages::contentfly_status_ressource_already_exists);
            }else{
                throw new ContentflyException(Messages::contentfly_general_ressource_already_exists, "$property::$value", Messages::contentfly_status_ressource_already_exists);
            }
        }catch(Exception $e){
            throw new ContentflyException($e->getMessage());
        }

        /**
         * Log update actions
         */
        if(!$isUnviversalUpdate) {
            $log = new Log();

            $log->setModelId($object->getId());
            $log->setModelName(ucfirst($entityShortName));
            $log->setUserCreated($this->app['auth.user']);
            $log->setMode(Log::UPDATED);

            if ($schema[$entityShortName]['settings']['labelProperty']) {
                try {
                    $labelGetter = 'get' . ucfirst($schema[$entityShortName]['settings']['labelProperty']);
                    $label = $object->$labelGetter();
                    $log->setModelLabel($label);
                }catch(Exception){

                }

            }

            $this->em->persist($log);

            foreach($usersRemoved as $userRemoved){
                $logUsrDel = new Log();

                $logUsrDel->setModelId($object->getId());
                $logUsrDel->setModelName(ucfirst($entityShortName));
                $logUsrDel->setUserCreated($this->app['auth.user']);
                $logUsrDel->setUsers($userRemoved);
                $logUsrDel->setMode(Log::USERDEL);

                if ($schema[$entityShortName]['settings']['labelProperty']) {
                    try {
                        $labelGetter = 'get' . ucfirst($schema[$entityShortName]['settings']['labelProperty']);
                        $label = $object->$labelGetter();
                        $logUsrDel->setModelLabel($label);
                    }catch(Exception){

                    }

                }

                $this->em->persist($logUsrDel);
            }

            $this->em->flush();
        }

        if(count($i18nProperties) && count($i18nObjects)) {
            return array('i18nObjects' => $i18nObjects, 'i18nProperties' => $i18nProperties);
        }else{
            return null;
        }
    }


    public function getAll($lastModified = null, $flatten = false, $filedata = null): array
    {
        /*
         * Bis 000-000-0007 sammelte diese Methode ihre Entities auf einem dritten, eigenen
         * Weg: eine fest verdrahtete Einschlussliste aus File, User und Group, dazu ein Lauf
         * ueber custom/Entity/ mit einem Pfad, der aus dem Repo herauszeigte — weshalb
         * /api/all bedingungslos mit HTTP 500 antwortete.
         *
         * Jetzt derselbe Weg wie in getDeleted(), der anderen Haelfte des Sync-Vertrags: das
         * Schema minus derselben Ausschlussliste. Vorher meldete getDeleted() Loeschungen fuer
         * Entities, die getAll() nie ausgeliefert hat — ein Client erfuhr vom Verschwinden von
         * Objekten, die er nie bekommen hatte. Fuer Clients ist die Aenderung additiv: Tag,
         * Option, OptionGroup und die Custom-Entities kommen hinzu, es faellt nichts weg.
         *
         * Dabei ist aufgefallen, dass excludeFromSync bis dahin ausschliesslich in
         * getCount() geprueft wurde — das Feld wirkte also auf die Bestandsstatistik, nie
         * auf den Endpunkt, nach dem es benannt ist. Die Pruefung steht jetzt auch hier.
         */
        $schema = $this->getSchema();

        /**
         * Die zweite Ausschlussliste ist weg (000-000-0013).
         *
         * Hier stand eine fest verdrahtete Liste — Folder, Token, Group, ThumbnailSetting,
         * Permission, Nav, NavItem, Log — in dreifacher Ausfertigung, in getAll(), getCount()
         * und getDeleted(). Sie stand in keiner Annotation und in keiner Konfiguration: Ein
         * Projekt konnte nicht erkennen, warum eine Entity nie synchronisiert wird.
         *
         * Die betroffenen Entities tragen jetzt `@PIM\Config(excludeFromSync=true)`, jede mit
         * ihrer Begruendung an der Klasse. Damit gibt es EINEN Mechanismus statt zwei, und er
         * steht im Schema, das jeder Client lesen kann.
         *
         * Zwei Eintraege der alten Liste waren tot: `PIM\Token` steht nicht im Schema (die
         * Entity leitet sich nicht von Base ab), `PIM\PushToken` gibt es im Baum nicht.
         *
         * `_hash` bleibt hier: Das ist kein Entity-Name, sondern der Schema-Hash. Es laesst
         * sich nicht annotieren, weil es keine Klasse gibt, an die man es schreiben koennte.
         */
        $helper   = new Helper();
        $entities = array();

        foreach(array_keys($schema) as $entityShortName){
            if($entityShortName === '_hash'){
                continue;
            }

            $entities[] = $helper->getFullEntityName($entityShortName);
        }

        $all = array();

        foreach($entities as $entityName){
            $entityShortcut = substr($entityName, strrpos($entityName, '\\') + 1);
            if(str_starts_with($entityName, 'Areanet\\PIM')){
                $entityShortcut = 'PIM\\'.$entityShortcut;
            }

            $entityNameAlias = 'a'.md5($entityShortcut);

            if(!empty($schema[$entityShortcut]['settings']['excludeFromSync'])){
                continue;
            }

            if(!($permission = Permission::isReadable($this->app['auth.user'], $entityShortcut))){
                continue;
            }

            $qb = $this->em->createQueryBuilder();

            $qb->select($entityNameAlias)
                ->from($entityName, $entityNameAlias);

            $qb->where("1 = 1");

            if($permission == \Areanet\PIM\Entity\Permission::OWN){
                $qb->andWhere("$entityNameAlias.userCreated = :userCreated OR FIND_IN_SET(:userCreated, $entityNameAlias.users) > 0");
                $qb->setParameter('userCreated', $this->app['auth.user']);
            }elseif($permission == \Areanet\PIM\Entity\Permission::GROUP){
                $group = $this->app['auth.user']->getGroup();
                if(!$group){
                    $qb->andWhere("$entityNameAlias.userCreated = :userCreated");
                }else{
                    $qb->andWhere("$entityNameAlias.userCreated = :userCreated OR FIND_IN_SET(:userGroup, $entityNameAlias.groups) > 0");
                    $qb->setParameter('userGroup', $group);
                }
                $qb->setParameter('userCreated', $this->app['auth.user']);
            }

            if($lastModified) {
                $qb->andWhere($entityNameAlias . '.modified >= :lastModified');
                $qb->setParameter('lastModified', $lastModified);
            }

            $query      = $qb->getQuery();
            $objects    = $query->getResult();


            $array = array();
            foreach($objects as $object){

                $objectData = $object->toValueObject($this->app, $entityShortcut, $flatten);

                if($object instanceof File && $filedata !== null){

                    $backendFS = new Backend\FileSystem();
                    foreach($filedata as $size){
                        $sizePrefix = $size == 'org' ? '' : $size.'-';
                        $path       = $backendFS->getPath($object);
                        $filePath   = $path.'/'.$sizePrefix.$object->getName();

                        if(file_exists($filePath)){
                            if(!isset($objectData->filedata)) $objectData->filedata = new stdClass();

                            $data   = file_get_contents($filePath);
                            $base64 = base64_encode($data);
                            $objectData->filedata->$size = $base64;
                        }
                    }
                }

                $array[] = $objectData;
            }

            //GET DELETED
            $qb = $this->em->createQueryBuilder();

            $qb->select('log')
                ->from('Areanet\PIM\Entity\Log', 'log')
                ->where('log.modelName = :modelName')
                ->andWhere("log.mode = 'DEL' OR log.mode = 'Gelöscht'")
                ->setParameter('modelName', $entityShortcut);

            if($lastModified) {
                $qb->andWhere('log.created >= :lastModified');
                $qb->setParameter('lastModified', $lastModified);
            }

            $query = $qb->getQuery();
            $objects = $query->getResult();

            foreach($objects as $object){
                $array[] = array(
                    'id' => $object->getModelId(),
                    'isDeleted' => true
                );
            }

            if(!count($array)){
                continue;
            }

            $all[$entityShortcut] = $array;
        }

        return $all;
    }

    /**
     * @throws ContentflyException
     * @throws ReflectionException
     */
    public function getCount($lastMofified, $entity = null): array
    {

        $data = array(
            'dataCount'     => 0,
            'filesCount'    => 0,
            'filesSize'     => 0,
        );

        $schema = $this->getSchema();

        /**
         * Was hier uebrig bleibt, ist KEINE Sync-Entscheidung (000-000-0013).
         *
         * Die gemeinsame Liste ist zu `excludeFromSync` an den Entities geworden und wird
         * unten geprueft. `PIM\File` bleibt hier stehen, weil Dateien in dieser Statistik
         * gesondert gezaehlt werden — `filesCount` und `filesSize` weiter oben. Sie ein
         * zweites Mal unter `details` zu fuehren, waere doppelt.
         */
        $details = array();
        foreach($schema as $entityName => $entityConfig){

            if($entity && $entity != $entityName) continue;

            if($entityName === '_hash' || $entityName === 'PIM\\File'){
                continue;
            }

            if(!($permission = Permission::isReadable($this->app['auth.user'], $entityName))){
                continue;
            }


            if($entityConfig['settings']['excludeFromSync']){
                continue;
            }

            $tableName = $entityConfig['settings']['dbname'];

            // ZAEHLEN STATT HOLEN (010-005-0001). Hier stand `SELECT 1`, und gezaehlt
            // wurde mit `rowCount()` — eine Zeile je Treffer ging ueber die Verbindung und in
            // den Speicher, nur um abgezaehlt zu werden. Bei einer grossen Tabelle ist das der
            // Unterschied zwischen einer Zahl und einem Datenuebertrag.
            //
            // Dazu ist `rowCount()` fuer eine LESEabfrage nicht zugesichert: DBAL sagt, der
            // Rueckgabewert haenge dann vom Treiber ab. Dass es unter MySQL ging, war kein
            // Vertrag. Befund aus 009-005-0002.
            $query = "SELECT COUNT(*) FROM `$tableName`";

            if($entityConfig['settings']['type'] == 'tree'){
                $treeTableName = $entityConfig['i18n'] ? 'pim_i18n_tree' : 'pim_tree';
                $query .= " INNER JOIN `$treeTableName` ON `$tableName`.id = `$treeTableName`.id";
            }

            $params  = array();
            $tsQuery = " WHERE 1=1";
            if($lastMofified){
                if(is_array($lastMofified)){
                    if(isset($lastMofified[$entityName])){
                        $tsQuery .= " AND `modified` > ?";
                        $params = array($lastMofified[$entityName]);
                    }
                }else{
                    $tsQuery .= " AND `modified` > ?";
                    $params = array($lastMofified);
                }
            }

            if($permission == \Areanet\PIM\Entity\Permission::OWN){
                $tsQuery .= " AND (userCreated_id = ? OR FIND_IN_SET(?, users) > 0)";
                $params[] = $this->app['auth.user']->getId();
                $params[] = $this->app['auth.user']->getId();
            }elseif($permission == \Areanet\PIM\Entity\Permission::GROUP){
                $group = $this->app['auth.user']->getGroup();
                if(!$group){
                    $tsQuery .= " AND userCreated_id = ?";
                    $params[] = $this->app['auth.user']->getId();
                }else{
                    $tsQuery .= " AND (userCreated_id = ? OR FIND_IN_SET(?, groups) > 0)";
                    $params[] = $this->app['auth.user']->getId();
                    $params[] = $group->getId();
                }
            }

            $query .= $tsQuery;

            $entityCount        = (int) $this->app['database']->executeQuery($query, $params)->fetchOne();
            $data['dataCount'] += $entityCount;
            $details[$entityName] = $entityCount;
            foreach($entityConfig['properties'] as $field => $fieldOptions){
                if($fieldOptions['type'] == 'multifile'){
                    $joinTableName = $fieldOptions['foreign'] ?:  $tableName . "_" . $field;
                    $joinQuery  = "
                        SELECT COUNT(*)
                        FROM `$joinTableName` 
                        INNER JOIN `pim_file`  
                        ON file_id = id";

                    $joinQuery .= $tsQuery;

                    $joinEntityCount    = (int) $this->app['database']->executeQuery($joinQuery, $params)->fetchOne();
                    $data['dataCount'] += $joinEntityCount;
                }

                if($fieldOptions['type'] == 'multijoin' && ! empty($fieldOptions['foreign'])){
                    $joinTableName = $fieldOptions['foreign'];
                    $joinField     = $fieldOptions['dbfield'];

                    if($entityConfig['settings']['type'] == 'tree'){
                        $treeTableName = $entityConfig['i18n'] ? 'pim_i18n_tree' : 'pim_tree';
                    }else{
                        $treeTableName = $tableName;
                    }

                    $joinQuery  = "
                        SELECT COUNT(*)
                        FROM `$joinTableName` 
                        INNER JOIN `$treeTableName`  
                        ON $joinField = id";

                    $joinQuery .= $tsQuery;

                    $joinEntityCount    = (int) $this->app['database']->executeQuery($joinQuery, $params)->fetchOne();

                    $data['dataCount'] += $joinEntityCount;
                }
            }
        }

        if(!$entity || $entity == 'PIM\\File') {
            $query = "SELECT COUNT(*) AS `records`, SUM(size) AS `size` FROM `pim_file`";

            $params = array();
            if ($lastMofified) {
                if (is_array($lastMofified)) {
                    if (isset($lastMofified['PIM\\File'])) {
                        $query .= " WHERE `modified` > ?";
                        $params = array($lastMofified['PIM\\File']);
                    }
                } else {
                    $query .= " WHERE `modified` > ?";
                    $params = array($lastMofified);
                }
            }

            // fetchAssoc() ist in DBAL 3 entfallen (009-005-0002).
            $files = $this->app['database']->fetchAssociative($query, $params);
            $data['filesCount'] = intval($files['records']);
            $data['filesSize'] = $files['size'] ?: 0;
        }

        $data['details']    = $details;
        return $data;
    }

    /**
     * @throws ContentflyException
     * @throws ReflectionException
     */
    public function getDeleted($lastMofified): array
    {

        $data = array();

        $schema = $this->getSchema();

        foreach($schema as $entityName => $entityConfig){

            if($entityName === '_hash'){
                continue;
            }

            /**
             * excludeFromSync — hier neu, siehe 000-000-0013.
             *
             * getDeleted() hat es NIE geprueft: Es hatte nur seine fest verdrahtete Liste.
             * getAll() und getCount() pruefen es seit 000-000-0007 beziehungsweise seit jeher.
             * Eine Entity, die aus dem Bestand ausgeschlossen ist, aber ihre Loeschungen
             * meldet, ergibt keinen Sinn — ein Sync-Client bekaeme Loeschmeldungen zu
             * Objekten, die er nie erhalten hat.
             */
            if(!empty($entityConfig['settings']['excludeFromSync'])){
                continue;
            }

            $query = "SELECT model_name, model_id FROM `pim_log` WHERE model_name = ? AND (mode = 'DEL' OR (mode = 'USERDEL' AND users = ?))";

            $params  = array($entityName, $this->app['auth.user']->getId());
            /**
             * `>=` statt `>` — die Grenzsekunde gehoert dazu (000-000-0013).
             *
             * `pim_log.created` ist ein datetime mit Sekundenaufloesung. Ein Lebenszyklus, der
             * in derselben Sekunde ablaeuft — bei je einem API-Aufruf der Normalfall —
             * hinterlaesst Zeilen mit identischem Zeitstempel. Mit `>` verliert ein
             * Sync-Client jede Loeschung, die in derselben Sekunde stattfand wie die, deren
             * Zeitstempel er sich gemerkt hat: Sie ist nicht groesser, also kommt sie nie.
             *
             * `>=` liefert die Grenzsekunde stattdessen erneut. Eine Loeschung doppelt zu
             * melden ist folgenlos — der Client loescht etwas, das schon weg ist. Eine
             * Loeschung zu verlieren ist es nicht: Das Objekt bleibt beim Client fuer immer
             * stehen, und nichts weist je darauf hin.
             *
             * getAll() filtert seit jeher mit `modified >= :lastModified`. Die beiden Haelften
             * derselben Synchronisation lagen also auf verschiedenen Seiten der Grenze.
             *
             * Die eigentliche Loesung waere eine hoehere Aufloesung oder eine monoton
             * steigende Sequenz. Beides braucht eine Spalte und damit eine Migration fuer
             * jedes Bestandsprojekt; das gehoert zum Kernel-Wechsel und nicht hierher.
             */
            $tsQuery = "";
            if($lastMofified){
                if(is_array($lastMofified)){
                    if(isset($lastMofified[$entityName])){
                        $tsQuery = " AND `created` >= ?";
                        $params[] = $lastMofified[$entityName];
                    }
                }else{
                    $tsQuery = " AND `created` >= ?";
                    $params[] = $lastMofified;
                }
            }

            $query .= $tsQuery;

            // fetchAll() ist in DBAL 3 entfallen (009-005-0002).
            if(($deletedObjects = $this->app['database']->fetchAllAssociative($query, $params))){
                $data   = array_merge($data, $deletedObjects);
            }

        }

        return $data;
    }

    public function getExtendedSchema(): array
    {
        /*
         * DER frontend-BLOCK IST VON SIEBEN AUF ZWEI SCHLUESSEL GESCHRUMPFT (000-000-0010).
         *
         * Entfallen sind customLogo, formImageSquarePreview, title, welcome und
         * login_redirect. Alle fuenf beschrieben Eigenschaften der PIM-Oberflaeche, die Epic
         * 012 entfernt hat — das Schema bewarb sie weiter.
         *
         * Die beiden verbliebenen sind keine Oberflaechen-Sache:
         *
         *   customNavigation  liest die Entities PIM\Nav und PIM\NavItem aus. Beide gibt es,
         *                     sie gehoeren zum Datenmodell und die Suite beruehrt sie.
         *   languages         kommt aus APP_LANGUAGES und bestimmt die Hauptsprache
         *                     (bootstrap.php setzt daraus APP_CMS_MAIN_LANG).
         *
         * Der Schluesselname "frontend" bleibt trotzdem. Ihn umzubenennen waere ein zweiter
         * Bruch fuer jeden Client, der ihn liest — und einer ohne Gegenwert.
         */
        $frontend = array(
            'customNavigation' => array(
                'enabled' => Adapter::getConfig()->FRONTEND_CUSTOM_NAVIGATION
            ),
            'languages' => Adapter::getConfig()->APP_LANGUAGES
        );

        $schema         = $this->app['schema'];
        $permissions    = $this->getPermissions();

        $permission     = Permission::isReadable($this->app['auth.user'], 'PIM\\NavItem');

        if(Adapter::getConfig()->FRONTEND_CUSTOM_NAVIGATION && $permission){
            $frontend['customNavigation']['items'] = array();



            $queryBuilder = $this->em->createQueryBuilder();
            $queryBuilder
                ->select("navItem")
                ->from("Areanet\PIM\Entity\NavItem", "navItem")
                ->join("navItem.nav", "nav")
                ->where('navItem.nav IS NOT NULL')
                ->orderBy('nav.sorting')
                ->orderBy('navItem.sorting');

            if($permission == \Areanet\PIM\Entity\Permission::OWN){
                $queryBuilder->andWhere("navItem.userCreated = :userCreated OR FIND_IN_SET(:userCreated, navItem.users) > 0");
                $queryBuilder->setParameter('userCreated', $this->app['auth.user']);
            }elseif($permission == \Areanet\PIM\Entity\Permission::GROUP){
                $group = $this->app['auth.user']->getGroup();
                if(!$group){
                    $queryBuilder->andWhere("navItem.userCreated = :userCreated");
                }else{
                    $queryBuilder->andWhere("navItem.userCreated = :userCreated OR FIND_IN_SET(:userGroup, navItem.groups) > 0");
                    $queryBuilder->setParameter('userGroup', $group);
                }
                $queryBuilder->setParameter('userCreated', $this->app['auth.user']);
            }

            $items = $queryBuilder->getQuery()->getResult();
            foreach($items as $item){

                $entityUriName = str_replace('Areanet\PIM\Entity', 'PIM/', $item->getEntity());
                $entityUriName = str_replace('Custom\Entity', '', $entityUriName);

                if(empty($frontend['customNavigation']['items'][$item->getNav()->getId()])){
                    $frontend['customNavigation']['items'][$item->getNav()->getId()] = array(
                        'title' => $item->getNav()->getTitle(),
                        'icon' => $item->getNav()->getIcon() ? $item->getNav()->getIcon() : 'glyphicon glyphicon-th-large',
                        'items' => array()
                    );
                }

                $frontend['customNavigation']['items'][$item->getNav()->getId()]['items'][] = array(
                    'entity' => $item->getEntity(),
                    'title'  => $item->getTitle() ?: $item->getEntity(),
                    'uri'    => $item->getUri() ? $item->getUri() : '#/list/'.$entityUriName,
                );
            }
        }

        $i18nPermissions = null;
        if(($group = $this->app['auth.user']->getGroup())){
            $i18nPermissions = $group->getLanguages();
        }

        return array('frontend' => $frontend, 'devmode' => Adapter::getConfig()->APP_DEBUG, 'version' => APP_VERSION.'/'.CUSTOM_VERSION, 'data' => $schema, 'permissions' => $permissions, 'i18nPermissions' => $i18nPermissions);
    }


    /**
     * @throws ContentflyException
     * @throws NonUniqueResultException
     */
    public function getList($entityName, $where = null, $order = null, $groupBy = null, $properties = array(), $lastModified = null, $flatten = false, $currentPage = 0, $itemsPerPage = 20, $lang = null, $untranslatedLang = null): ?array
    {
        if(!empty($lastModified)) {
            try {
                $lastModified = new Datetime($lastModified);
            } catch (Exception) {

            }
        }

        $helper             = new Helper();
        $entityFullName     = $helper->getFullEntityName($entityName);
        $entityShortName    = $helper->getShortEntityName($entityName);

        $entityNameAlias = 'a'.md5($entityShortName);

        $schema  = $this->app['schema'];

        if(!isset($schema[$entityShortName])){
            throw new ContentflyException(Messages::contentfly_general_unknown_entity, $entityShortName, Messages::contentfly_status_not_found);
        }

        if(!($permission = Permission::isReadable($this->app['auth.user'], $entityShortName))){
            throw new ContentflyException(Messages::contentfly_general_permission_denied, $entityShortName, Messages::contentfly_status_access_denied);
        }

        $queryBuilder = $this->em->createQueryBuilder();
        $queryBuilder
            ->select("count(".$entityNameAlias.")")
            ->from($entityFullName, $entityNameAlias)
            ->andWhere("$entityNameAlias.isIntern = false");


        if($permission == \Areanet\PIM\Entity\Permission::OWN){
            $queryBuilder->andWhere("$entityNameAlias.userCreated = :userCreated OR FIND_IN_SET(:userCreated, $entityNameAlias.users) > 0");
            $queryBuilder->setParameter('userCreated', $this->app['auth.user']);
        }elseif($permission == \Areanet\PIM\Entity\Permission::GROUP){
            $group = $this->app['auth.user']->getGroup();
            if(!$group){
                $queryBuilder->andWhere("$entityNameAlias.userCreated = :userCreated");
            }else{
                $queryBuilder->andWhere("$entityNameAlias.userCreated = :userCreated OR FIND_IN_SET(:userCreated, $entityNameAlias.users) > 0 OR FIND_IN_SET(:userGroup, $entityNameAlias.groups) > 0");
                $queryBuilder->setParameter('userGroup', $group);
            }
            $queryBuilder->setParameter('userCreated', $this->app['auth.user']);
        }

        if($lastModified && !$untranslatedLang){
            $queryBuilder->andWhere($entityNameAlias.'.modified >= :lastModified')->setParameter('lastModified', $lastModified);
        }

        if($schema[$entityShortName]['settings']['i18n']){
            if(empty($lang)){
                throw new ContentflyException(Messages::contentfly_i18n_missing_lang_param, $entityShortName);
            }

            if($untranslatedLang){
                $queryBuilder->andWhere($entityNameAlias.'.lang = :lang')->setParameter('lang', $untranslatedLang);
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->notIn(
                        $entityNameAlias.'.id',
                        $this->em->createQueryBuilder()
                            ->select($entityNameAlias.'sub.id')
                            ->from($entityFullName, $entityNameAlias.'sub')
                            ->where($entityNameAlias."sub.lang = :sublang")
                            ->getDQL()
                    )
                )->setParameter('sublang', $lang);
            }else{
                $queryBuilder->andWhere($entityNameAlias.'.lang = :lang')->setParameter('lang', $lang);
            }
        }

        if($where && !$untranslatedLang){
            $joinedCounter      = 0;
            foreach($where as $field => $value){

                if(!isset($schema[$entityShortName]['properties'][$field])){

                    continue;
                }

                if($schema[$entityShortName]['properties'][$field]['type'] == 'multijoin' || $schema[$entityShortName]['properties'][$field]['type'] == 'checkbox'){
                    if(isset($schema[$entityShortName]['properties'][$field]['mappedBy'])){
                        if($value == -1) {
                            $mappedBy           = $schema[$entityShortName]['properties'][$field]['mappedBy'];
                            $queryBuilder->leftJoin("$entityNameAlias.$field", "joined$joinedCounter");
                            $queryBuilder->andWhere("joined$joinedCounter.$mappedBy IS NULL");
                        }else{
                            $searchJoinedEntity = $schema[$entityShortName]['properties'][$field]['accept'];
                            $searchJoinedObject = $this->em->getRepository($searchJoinedEntity)->find($value);
                            $mappedBy           = $schema[$entityShortName]['properties'][$field]['mappedBy'];

                            $queryBuilder->leftJoin("$entityNameAlias.$field", "joined$joinedCounter");
                            $queryBuilder->andWhere("joined$joinedCounter.$mappedBy = :$field");
                            $queryBuilder->setParameter($field, $searchJoinedObject);
                        }

                    }else{

                        $queryBuilder->leftJoin("$entityNameAlias.$field", 'k');
                        if($value == -1){
                            $queryBuilder->andWhere("k.id IS NULL");
                        }else{
                            $queryBuilder->andWhere("k.id = :$field");
                            $queryBuilder->setParameter($field, $value);
                        }

                    }

                }else{
                    switch($schema[$entityShortName]['properties'][$field]['type']){
                        case 'join':
                            if($value == -1){
                                $queryBuilder->andWhere("$entityNameAlias.$field IS NULL");
                            }else{
                                $queryBuilder->andWhere("$entityNameAlias.$field = :$field");
                                $queryBuilder->setParameter($field, $value);
                            }


                            break;
                        case 'virtualjoin':

                            $queryBuilder->andWhere("FIND_IN_SET(:$field, $entityNameAlias.$field) > 0");
                            $queryBuilder->setParameter($field, $value);
                            break;
                        case 'boolean':
                            if(strtolower($value) == 'false'){
                                $value = 0;
                            }elseif(strtolower($value) == 'true'){
                                $value = 1;
                            }else{
                                $value = boolval($value);
                            }

                            $isNull = !$value ? "OR $entityNameAlias.$field IS NULL" : '';

                            $queryBuilder->andWhere("$entityNameAlias.$field = :$field $isNull");
                            $queryBuilder->setParameter($field, $value);

                            break;
                        case 'integer':
                            $value = intval($value);

                            $queryBuilder->andWhere("$entityNameAlias.$field = :$field");
                            $queryBuilder->setParameter($field, $value);

                            break;
                        default:

                            $queryBuilder->andWhere("$entityNameAlias.$field = :$field");
                            $queryBuilder->setParameter($field, $value);

                            break;
                    }


                }

            }

            if(isset($where['fulltext'])){
                $orX = $queryBuilder->expr()->orX();
                $fulltextTypes = array('string', 'text', 'textarea', 'rte');

                $orX->add("$entityNameAlias.id = :FT_id");
                $queryBuilder->setParameter("FT_id", $where['fulltext']);

                foreach($schema[$entityShortName]['properties'] as $field => $fieldOptions){

                    if(in_array($fieldOptions['type'], $fulltextTypes)){
                        $orX->add("$entityNameAlias.$field LIKE :FT_$field");
                        $queryBuilder->setParameter("FT_$field", '%' . $where['fulltext'] . '%');
                    }
                }

                $queryBuilder->andWhere($orX);
            }

            if(isset($where['mimetypes'])){

                if($where['mimetypes'] == 'other'){
                    $types = array();
                    foreach($this->_MIMETYPES as $mimetypes){
                        $types = array_merge($types, $mimetypes);
                    }
                    $queryBuilder->andWhere($queryBuilder->expr()->notIn("$entityNameAlias.type", $types));
                }elseif(isset($this->_MIMETYPES[$where['mimetypes']])){

                    $queryBuilder->andWhere($queryBuilder->expr()->in("$entityNameAlias.type", $this->_MIMETYPES[$where['mimetypes']]));

                }


            }
        }

        $event = new Event();
        $event->setParam('request',        $this->request);
        $event->setParam('entity',         $entityShortName);
        $event->setParam('queryBuilder',   $queryBuilder);
        $event->setParam('app',            $this->app);
        $event->setParam('user',           $this->app['auth.user']);

        $this->app['dispatcher']->dispatch($event, 'pim.entity.before.list');

        $query          = $queryBuilder->getQuery();
        $totalObjects   = $query->getSingleScalarResult();

        if($currentPage*$itemsPerPage > $totalObjects){
            $currentPage = ceil($totalObjects/$itemsPerPage);
        }

        if($currentPage) {
            $queryBuilder
                ->setFirstResult($itemsPerPage * ($currentPage - 1))
                ->setMaxResults($itemsPerPage)
            ;
        }

        if($order !== null){
            foreach($order as $orderBy => $orderSort){
                $queryBuilder->addOrderBy($entityNameAlias.'.'.$orderBy, $orderSort);
            }
        }else{
            $queryBuilder->orderBy($entityNameAlias.'.id', 'DESC');
        }

        if($groupBy){
            $queryBuilder->groupBy($entityNameAlias.".".$groupBy);
        }

        $validProperties            = array();
        if(count($properties)){

            foreach($properties as $name){

                if(!isset($schema[$entityShortName]['properties'][$name]) || in_array($name, array('id', 'lang'))){
                    continue;
                }

                $config = $schema[$entityShortName]['properties'][$name];

                if(in_array($config['type'], array('multijoin', 'multifile', 'checkbox'))){
                    continue;
                }

                if(in_array($config['type'], array('join', 'file', 'radio'))){
                    continue;
                }

                $validProperties[] = $name;
            }

            $validProperties[] = 'id';
            if($schema[$entityShortName]['settings']['i18n']){
                $validProperties[] = 'lang';
            }

            $queryBuilder->select('partial '.$entityNameAlias.'.{'.implode(',', $validProperties).'}');

        }else{
            $queryBuilder->select($entityNameAlias);

        }

        $forceLoadPartial = false;

        foreach ($schema[$entityShortName]['properties'] as $field => $config) {
            if (count($properties) && !in_array($field, $properties)) continue;

            if($config['type'] == 'radio' || $config['type'] == 'join' || $config['type'] == 'file'){
                $joinedShortEntity = match ($config['type']) {
                    'file' => 'PIM\\File',
                    'radio' => 'PIM\\Option',
                    default => $helper->getShortEntityName($config['accept']),
                };

                if ($schema[$joinedShortEntity]['settings']['i18n']) {
                    $queryBuilder->leftJoin("$entityNameAlias.$field", 'a_'.$field, Join::WITH, "a_$field.lang = :lang");
                    $queryBuilder->setParameter('lang', $lang);
                    if(count($properties) && $schema[$joinedShortEntity]['settings']['type'] != 'tree') {
                        $labelProperty = $schema[$joinedShortEntity]['settings']['labelProperty'];
                        $labelPropertyField = $labelProperty && $schema[$joinedShortEntity]['properties'][$labelProperty]  ? ','.$labelProperty : '';
                        $queryBuilder->addSelect('partial '.'a_'.$field.'.{id, lang'.$labelPropertyField.'}');
                        $forceLoadPartial = true;
                    }else{
                        $queryBuilder->addSelect( 'a_'.$field);
                    }
                }else{
                    $queryBuilder->leftJoin("$entityNameAlias.$field", 'a_'.$field);
                    if(count($properties) && $schema[$joinedShortEntity]['settings']['type'] != 'tree') {
                        $labelProperty = $schema[$joinedShortEntity]['settings']['labelProperty'];
                        $labelPropertyField = $labelProperty && $schema[$joinedShortEntity]['properties'][$labelProperty]  ? ','.$labelProperty : '';
                        $queryBuilder->addSelect('partial '.'a_'.$field.'.{id'.$labelPropertyField.'}');
                    }else{
                        $queryBuilder->addSelect('a_'.$field);
                    }
                    $forceLoadPartial = true;
                }
            }
        }

        $query   = $queryBuilder->getQuery();

        if($forceLoadPartial){
            $query->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true);
        }
//die($query->getDQL());
        $objects = $query->getResult();

        if(!$objects){
            return null;
        }

        $array = array();
        foreach($objects as $object){

            $objectData = $object->toValueObject($this->app, $entityShortName,  $flatten, $properties);

            $array[] = $objectData;

        }

        return array('totalObjects' => $totalObjects, 'objects' => $array);
    }



    protected function getPermissions(): array
    {
        $schema = $this->app['schema'];

        $permissions = array();
        foreach($schema as $entityName => $config){

            // 'export' und 'extended' sind mit 000-000-0012 entfallen. Beide standen hier,
            // ohne dass irgendein Endpunkt sie geprueft haette — siehe den Klassenkommentar
            // von Areanet\PIM\Classes\Permission.
            $permissions[$entityName] = array(
                'readable'  => Permission::isReadable($this->app['auth.user'], $entityName),
                'writable'  => Permission::isWritable($this->app['auth.user'], $entityName),
                'deletable' => Permission::isDeletable($this->app['auth.user'], $entityName)
            );
        }

        return $permissions;
    }

    /**
     * @throws ReflectionException
     * @throws ContentflyException
     */
    public function getSchema(){
        $cacheFile = Paths::data().'/cache/schema.cache';

        if(Adapter::getConfig()->APP_ENABLE_SCHEMA_CACHE){

            if(file_exists($cacheFile)){

                return unserialize(file_get_contents($cacheFile));
            }
        }

        $entities = [];
        $entityFolders = [];
        $entityFolder = Paths::projectEntities().'/';

        foreach (new DirectoryIterator($entityFolder) as $fileInfo) {
            if ($fileInfo->isDot()) continue;

            if ($fileInfo->isDir()) {
                $entityFolders[] = $fileInfo->getFilename();
            } elseif ($fileInfo->isFile() && $fileInfo->getExtension() === 'php' && !str_starts_with($fileInfo->getBasename('.php'), '.')) {
                $entities[] = $fileInfo->getBasename('.php');
            }
        }

        sort($entityFolders);

        foreach($entityFolders as $folder) {
            foreach (new DirectoryIterator($entityFolder.$folder) as $subfileInfo) {
                if ($subfileInfo->isFile() && $subfileInfo->getExtension() === 'php' && !str_starts_with($subfileInfo->getBasename('.php'), '.')) {
                    $entities[] = $folder . '\\' . $subfileInfo->getBasename('.php');
                }
            }
        }

        $entities = array_merge($entities, $this->app['pluginManager']->getEntities());

        $entities[] = "PIM\\File";
        $entities[] = "PIM\\Folder";
        $entities[] = "PIM\\Tag";
        $entities[] = "PIM\\User";
        $entities[] = "PIM\\Group";
        $entities[] = "PIM\\Log";
        $entities[] = "PIM\\ThumbnailSetting";
        $entities[] = "PIM\\Permission";
        $entities[] = "PIM\\Nav";
        $entities[] = "PIM\\NavItem";
        $entities[] = "PIM\\Option";
        $entities[] = "PIM\\OptionGroup";

        $data           = array();
        $helper         = new Helper();
        $permissions    = array();

        foreach($entities as $entity){

            $className = $helper->getFullEntityName($entity);
            $object    = new $className();
            $reflect   = new ReflectionClass($object);
            $props     = $reflect->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);
            $entityName = $entity;

            $defaultValues = $reflect->getDefaultProperties();

            $metadaten = new Metadatenleser();

            // Siehe oben: 'export' und 'extended' entfallen mit 000-000-0012.
            $permissions[$entityName] = array(
                'readable'  => $this->app['auth.user'] ? Permission::isReadable($this->app['auth.user'], $entityName) : 0,
                'writable'  => $this->app['auth.user'] ? Permission::isWritable($this->app['auth.user'], $entityName) : 0,
                'deletable' => $this->app['auth.user'] ? Permission::isDeletable($this->app['auth.user'], $entityName) : 0
            );

            $i18n = false;
            if($object instanceof BaseI18n){

                if(!defined('APP_CMS_MAIN_LANG')){
                    throw new ContentflyException('contentfly_i18n_undefined_languages');
                }

                $i18n = true;
            }

            $settings = array(
                'sortBy' => 'created',
                'sortRestrictTo' => null,
                'sortOrder' => 'DESC',
                'isSortable' => false,
                'labelProperty' => null,
                'type' => 'default',
                'dbname' => null,
                'i18n' => $i18n,
                'excludeFromSync' => false
            );



            if($object instanceof BaseSortable || $object instanceof BaseI18nSortable){
                $settings['sortBy']     = 'sorting';
                $settings['sortOrder']  = 'ASC';
                $settings['isSortable'] = true;
            }

            if($object instanceof BaseTree || $object instanceof BaseI18nTree){
                $settings['type']  = 'tree';
            }

            $classAnnotations = $metadaten->klasse($reflect);

            $skipEntity = false;

            foreach($classAnnotations as $classAnnotation) {

                if($classAnnotation instanceof MappedSuperclass){
                    $skipEntity = true;
                    break;
                }

                if ($classAnnotation instanceof Table) {
                    $settings['dbname'] = $classAnnotation->name ?: null;
                }

                if ($classAnnotation instanceof \Areanet\PIM\Classes\Annotations\Config) {
                    $settings['labelProperty']  = $classAnnotation->labelProperty ?: $settings['labelProperty'];
                    $settings['sortBy']         = $classAnnotation->sortBy ?: $settings['sortBy'];
                    $settings['sortOrder']      = $classAnnotation->sortOrder ?: $settings['sortOrder'];
                    $settings['sortRestrictTo'] = $classAnnotation->sortRestrictTo ?: $settings['sortRestrictTo'];
                    $settings['excludeFromSync']= $classAnnotation->excludeFromSync ?: false;
                }

                $event = new Event();
                $event->setParam('classAnnotation', $classAnnotation);
                $event->setParam('settings',        $settings);
                $this->app['dispatcher']->dispatch($event, 'pim.schema.after.classAnnotation');
                $settings = $event->getParam('settings');
            }

            if($skipEntity) continue;

            $properties         = array();
            $customProperties   = array();

            foreach ($props as $prop) {


                $reflectionProperty = new ReflectionProperty($className, $prop->getName());


                $propertyAnnotations = $metadaten->eigenschaft($reflectionProperty);

                $allPropertyAnnotations = array();
                foreach($propertyAnnotations as $propertyAnnotation){
                    $allPropertyAnnotations[get_class($propertyAnnotation)] = $propertyAnnotation;

                    $event = new Event();
                    $event->setParam('propertyAnnotation', $propertyAnnotation);
                    $event->setParam('properties', $customProperties[$prop->getName()] ?? array());
                    $this->app['dispatcher']->dispatch($event, 'pim.schema.after.propertyAnnotation');

                    if(($customProperties = $event->getParam('properties'))){
                        $customProperties[$prop->getName()] =  $customProperties;
                    }

                }
                krsort($allPropertyAnnotations);

                $lastMatchedPriority = -1;

                foreach($this->app['typeManager']->getTypes() as $type){
                    if($type->doMatch($allPropertyAnnotations) && $type->getPriority() >= $lastMatchedPriority){
                        $type->setEntitySettings($settings);

                        $propertySchema                 = $type->processSchema($prop->getName(), $defaultValues[$prop->getName()], $allPropertyAnnotations, $entityName);
                        $properties[$prop->getName()]   = $propertySchema;

                        if($prop->getName() == 'treeParent'){
                            $properties[$prop->getName()]['accept'] = $className;
                        }

                        $lastMatchedPriority = $type->getPriority();

                    }
                }

                /*
                 * EIN FELD OHNE PASSENDEN TYP FAELLT NICHT MEHR STILL AUS DEM SCHEMA
                 * (000-000-0017).
                 *
                 * Griff keiner der registrierten Typen, blieb $properties fuer diese
                 * Eigenschaft schlicht ungesetzt: kein Eintrag, keine Warnung, kein Hinweis.
                 * Lesen lieferte das Feld nicht, Schreiben scheiterte mit
                 * contentfly_general_unknown_property — und niemand erfuhr, warum. Die
                 * Vorlage custom/ fuehrte mit einem json-Feld genau diesen Fall vor.
                 *
                 * Geworfen wird NICHT: Ein Projekt mit einem exotischen Spaltentyp koennte
                 * sonst nach einem Update sein Schema nicht mehr aufbauen. Eine Warnung
                 * landet im Log, und die Suite setzt failOnWarning — dort faellt es sofort
                 * auf, ohne im Betrieb etwas umzuwerfen.
                 */
                if (empty($properties[$prop->getName()]) && isset($allPropertyAnnotations['Doctrine\\ORM\\Mapping\\Column'])) {
                    trigger_error(
                        sprintf(
                            'Kein Contentfly-Typ passt auf %s::%s (Spaltentyp "%s") — das Feld fehlt im API-Schema.',
                            $entityName,
                            $prop->getName(),
                            $allPropertyAnnotations['Doctrine\\ORM\\Mapping\\Column']->type
                        ),
                        E_USER_WARNING
                    );
                }

                if(!empty($properties[$prop->getName()]) && !empty($customProperties[$prop->getName()])){
                    $properties[$prop->getName()] = array_merge($properties[$prop->getName()], $customProperties[$prop->getName()]);
                }


            }

            $data[$entity] = array(
                'settings' => $settings,
                'properties' => $properties
            );
        }

        $data['_hash'] = md5(serialize($data).serialize($permissions));

        if(Adapter::getConfig()->APP_ENABLE_SCHEMA_CACHE){

            file_put_contents($cacheFile, serialize($data));
        }

        return $data;
    }

    /**
     * @throws ContentflyException
     * @throws MappingException
     * @throws ContentflyI18NException
     * @throws NonUniqueResultException
     */
    public function getSingle($entityName, $id = null, $where = null, $lang = null, $returnObject = false, $compareToLang = null, $loadJoinedLang = null, $clearEM = false){

        $helper             = new Helper();
        $entityFullName     = $helper->getFullEntityName($entityName);
        $entityShortName    = $helper->getShortEntityName($entityName);

        $schema = $this->app['schema'];
        if(!isset($schema[$entityShortName])){
            throw new ContentflyException(Messages::contentfly_general_unknown_entity, $entityShortName, Messages::contentfly_status_not_found);
        }

        if(!($permission = Permission::isReadable($this->app['auth.user'], $entityName))){
            throw new ContentflyException(Messages::contentfly_general_access_denied, $entityShortName, Messages::contentfly_status_access_denied);
        }

        $entityNameAlias = 'a'.md5($entityShortName);

        $queryBuilder = $this->em->createQueryBuilder();
        if($clearEM) $this->em->clear($entityFullName);
        $queryBuilder
            ->select($entityNameAlias)
            ->from($entityFullName, $entityNameAlias);


        if($id){
            $queryBuilder
                ->where("$entityNameAlias.id = :id")
                ->setParameter('id', $id);
        }elseif($where){
            foreach($where as $field => $value){
                $queryBuilder
                    ->andWhere("$entityNameAlias.$field = :$field")
                    ->setParameter($field, $value);
            }
        }else{
            throw new ContentflyException(Messages::contentfly_general_missing_params, $entityShortName);
        }

        if($schema[$entityShortName]['settings']['i18n']){
            if(empty($lang)){
                throw new ContentflyException(Messages::contentfly_i18n_missing_lang_param, $entityShortName);
            }
            $queryBuilder
                ->andWhere("$entityNameAlias.lang = :lang")
                ->setParameter('lang', $lang);
        }

        foreach ($schema[$entityShortName]['properties'] as $field => $config) {


            switch ($config['type']) {
                case 'onejoin':
                    $joinedEntity = $helper->getShortEntityName($config['accept']);
                    if ($schema[$joinedEntity]['settings']['i18n']) {
                        $queryBuilder->leftJoin("$entityNameAlias.$field", $field, Join::WITH, "$field.lang = :lang");
                        $queryBuilder->addSelect($field);
                    }
                    break;
                case 'join':
                    $joinedEntity = $helper->getShortEntityName($config['accept']);
                    if ($schema[$joinedEntity]['settings']['i18n']) {
                        $queryBuilder->leftJoin("$entityNameAlias.$field", $entityNameAlias.$field, Join::WITH, $entityNameAlias.$field.".lang = :loadJoinedLang");
                        $queryBuilder->addSelect($entityNameAlias.$field);

                        if($loadJoinedLang){
                            $queryBuilder->setParameter('loadJoinedLang', $loadJoinedLang);
                        }else{
                            $queryBuilder->setParameter('loadJoinedLang', $lang);
                        }

                        foreach($schema[$joinedEntity]['properties'] as $subfield => $subconfig){
                            if ($subconfig['type'] == 'join') {
                                $joinedSubEntity = $helper->getShortEntityName($subconfig['accept']);
                                if ($schema[$joinedSubEntity]['settings']['i18n']) {

                                    $queryBuilder->leftJoin($entityNameAlias . $field . '.' . $subfield, $entityNameAlias . $field . $subfield, Join::WITH, $entityNameAlias . $field . $subfield . ".lang = :loadJoinedLang");
                                    $queryBuilder->addSelect($entityNameAlias . $field . $subfield);

                                    if ($loadJoinedLang) {
                                        $queryBuilder->setParameter('loadJoinedLang', $loadJoinedLang);
                                    } else {
                                        $queryBuilder->setParameter('loadJoinedLang', $lang);
                                    }
                                }
                            }
                        }
                    }
                    break;
                case 'multijoin':
                    $joinedEntity = $helper->getShortEntityName($config['accept']);
                    if ($schema[$joinedEntity]['settings']['i18n']) {
                        $queryBuilder->leftJoin("$entityNameAlias.$field", $field, Join::WITH, "$field.lang = :loadJoinedLang");
                        $queryBuilder->addSelect($field);

                        if($loadJoinedLang){
                            $queryBuilder->setParameter('loadJoinedLang', $loadJoinedLang);
                        }else{
                            $queryBuilder->setParameter('loadJoinedLang', $lang);
                        }
                    }
                    break;
            }
        }

        $object = $queryBuilder->getQuery()->getOneOrNullResult();

        /**
         * Nicht gefunden heisst null (000-000-0006).
         *
         * BIS HIERHER GAB DIESE METHODE EINE JsonResponse ZURUECK — eine fertige HTTP-Antwort
         * aus einer Klasse, die keine Controller ist. Jeder interne Aufrufer prueft mit
         * `if(!$object)`, und eine JsonResponse ist wahr. Die Pruefung lief also ins Leere, und
         * der Code danach arbeitete mit der Antwort weiter, als waere sie das Objekt:
         *
         *   Helper::getUsersRemoved(): Argument #1 must be of type ...Entity\Base,
         *   ...HttpFoundation\JsonResponse given, called in Api.php on line 488
         *
         * Das war die Ursache dafuer, dass eine unbekannte Id ueber die API als 500 ankam,
         * obwohl doUpdate() und doDelete() jeweils eine ContentflyException mit 404 vorsehen —
         * sie wurden nie erreicht. Ein TypeError ist kein Exception, und die Fehlerkette von
         * Silex nimmt nur Exceptions an; deshalb fiel er bis zum globalen Handler durch.
         *
         * `/api/single` gab die Antwort sogar aus: Der Aufrufer bekam 200 und als Rumpf
         * `data: {"headers": {}}` — die JsonResponse, durch json_encode gedreht.
         */
        if (!$object) {
            return null;
        }

        if($compareToLang && $compareToLang != $lang) {
            if(!$loadJoinedLang) {
                //Bestehenden übersetzten Datensatz bearbeiten
                try {
                    $compareObject = $this->getSingle($entityShortName, $id, $where, $compareToLang, true, null, null, true);
                } catch (Exception) {
                    $compareObject = null;
                }

                if ($compareObject) {

                    foreach ($schema[$entityShortName]['properties'] as $field => $config) {
                        $getter = 'get' . ucfirst($field);
                        switch ($config['type']) {
                            case 'join':

                                if ($object->$getter() && !$compareObject->$getter()) {
                                    $helper = new Helper();
                                    throw new ContentflyI18NException(Messages::contentfly_i18n_missing_translations, $helper->getShortEntityName($config['accept']), $compareToLang);
                                }
                                break;
                            case 'multijoin':
                                $a1 = $compareObject->$getter() ? $compareObject->$getter() : array();
                                $a2 = $object->$getter() ? $object->$getter() : array();

                                if (count($a1) != count($a2)) {
                                    $helper = new Helper();
                                    throw new ContentflyI18NException(Messages::contentfly_i18n_missing_translations, $helper->getShortEntityName($config['accept']), $compareToLang);
                                }
                                break;
                        }
                    }
                }
            }else{
                //Datensatz neu übersetzen

                try {
                    $compareObject = $this->getSingle($entityShortName, $id, $where, $lang, true, null, null, true);
                } catch (Exception) {
                    $compareObject = null;
                }

                if ($compareObject) {
                    foreach ($schema[$entityShortName]['properties'] as $field => $config) {
                        $getter = 'get' . ucfirst($field);
                        switch ($config['type']) {
                            case 'join':
                                if ($compareObject->$getter() && !$object->$getter()) {
                                    $helper = new Helper();
                                    throw new ContentflyI18NException(Messages::contentfly_i18n_missing_translations, $helper->getShortEntityName($config['accept']), $compareToLang);
                                }
                                break;
                            case 'multijoin':
                                $a1 = $compareObject->$getter() ? $compareObject->$getter() : array();
                                $a2 = $object->$getter() ? $object->$getter() : array();

                                if (count($a1) != count($a2)) {
                                    $helper = new Helper();
                                    throw new ContentflyI18NException(Messages::contentfly_i18n_missing_translations, $helper->getShortEntityName($config['accept']), $compareToLang);
                                }
                                break;
                        }
                    }
                }

            }
        }

        if($permission == \Areanet\PIM\Entity\Permission::OWN && ($object->getUserCreated() != $this->app['auth.user'] && !$object->hasUserId($this->app['auth.user']->getId()))){
            throw new ContentflyException(Messages::contentfly_general_access_denied, "$entityShortName::$id", Messages::contentfly_status_access_denied);
        }

        if($permission == \Areanet\PIM\Entity\Permission::GROUP){
            if($object->getUserCreated() != $this->app['auth.user'] && !$object->hasUserId($this->app['auth.user']->getId())){
                $group = $this->app['auth.user']->getGroup();
                if(!($group && $object->hasGroupId($group->getId()))){
                    throw new ContentflyException(Messages::contentfly_general_access_denied, "$entityShortName::$id", Messages::contentfly_status_access_denied);
                }
            }
        }

        return $returnObject ? $object : $object->toValueObject($this->app, $entityShortName, false);
    }

    protected function getTableName($entityName, $tablename){

        if(empty($this->app['schema'][$entityName])){
            return $tablename;
        }

        return isset($this->app['schema'][$entityName]['settings']['dbname']) ? $this->app['schema'][$entityName]['settings']['dbname'] : $entityName;
    }

    /**
     * @throws ContentflyException
     */
    public function getTranslations($entityName, $lang): array
    {

        $helper             = new Helper();
        $entityShortName    = $helper->getShortEntityName($entityName);

        if(!(Permission::isReadable($this->app['auth.user'], $entityName))){
            throw new ContentflyException(Messages::contentfly_general_access_denied, $entityShortName, Messages::contentfly_status_access_denied);
        }

        $schema = $this->app['schema'];

        if(empty($schema[$entityName])){
            throw new ContentflyException(Messages::contentfly_general_invalid_entity, $entityShortName);
        }


        if(!$schema[$entityName]['settings']['i18n']){
            throw new ContentflyException(Messages::contentfly_general_invalid_base_entity, $entityShortName);
        }

        if(empty($lang)){
            throw new ContentflyException(Messages::contentfly_i18n_missing_lang_param, $entityShortName);
        }

        $dbName       = $schema[$entityName]['settings']['dbname'];
        $queryBuilder = $this->database->createQueryBuilder();

        $queryBuilder
            ->select('lang', 'COUNT(*) AS records')
            ->from($dbName)
            ->where("id NOT IN (SELECT id FROM $dbName WHERE lang = :lang) ")
            ->groupBy('lang')
            ->setParameter('lang', $lang);

        // executeQuery() statt execute() — letzteres ist in DBAL 3 @deprecated — und
        // fetchAllAssociative() statt fetchAll(), das am Result entfallen ist (009-005-0002).
        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @throws ContentflyException
     */
    public function getTree($entityName, $parent, $properties = array(), $lang = null): array
    {

        $helper             = new Helper();
        $schema             = $this->app['schema'];

        $entityFullName     = $helper->getFullEntityName($entityName);
        $entityShortName    = $helper->getShortEntityName($entityName);
        $entityNameAlias    = 'entity'.md5($entityName);
        $entityParentAlias  = 'entityparent'.md5($entityName);
        $properties         = is_array($properties) ? $properties : array();

        if(!isset($schema[$entityShortName])){
            throw new ContentflyException(Messages::contentfly_general_unknown_entity, $entityShortName, Messages::contentfly_status_not_found);
        }

        $i18n               = $schema[$entityShortName]['settings']['i18n'];

        $queryBuilder = $this->em->createQueryBuilder();
        $queryBuilder->from($entityFullName, $entityNameAlias)
            ->where("$entityNameAlias.isIntern = false")
            ->orderBy($entityNameAlias.'.sorting', 'ASC');

        if($i18n){
            $queryBuilder->andWhere("$entityNameAlias.lang = :lang");
            $queryBuilder->setParameter('lang', $lang);
        }

        if($parent){
            if($i18n){
                $queryBuilder->join("$entityNameAlias.treeParent", $entityParentAlias);
                $queryBuilder->andWhere("$entityParentAlias.id = :treeParentId AND $entityParentAlias.lang=:lang");
                $queryBuilder->setParameter('treeParentId', $parent->getId());
            }else{
                $queryBuilder->andWhere("$entityNameAlias.treeParent = :treeParent");
                $queryBuilder->setParameter('treeParent', $parent);
            }

        }else{
            $queryBuilder->andWhere("$entityNameAlias.treeParent IS NULL");
        }

        if(count($properties)){
            $properties[] = 'id';
            if($i18n){
                $properties[] = 'lang';
            }

            $partialProperties = implode(',', $properties);
            $queryBuilder->select('partial '.$entityNameAlias.'.{'.$partialProperties.'}');
        }else{
            $queryBuilder->select($entityNameAlias);
        }


        $query   = $queryBuilder->getQuery();
        $objects = $query->getResult();

        $array   = array();

        foreach($objects as $object){
            $data = $object->toValueObject($this->app, $entityShortName, true, $properties);
            $data->treeChilds = $this->getTree($entityShortName, $object, $properties, $lang);
            $array[] = $data;
        }

        return $array;
    }

    /**
     * @throws ContentflyException
     */
    public function getTree2($entityName, $lang = null): array
    {
        $helper             = new Helper();
        $schema             = $this->app['schema'];

        $entityShortName    = $helper->getShortEntityName($entityName);

        if(!isset($schema[$entityShortName])){
            throw new ContentflyException(Messages::contentfly_general_unknown_entity, $entityShortName, Messages::contentfly_status_not_found);
        }

        $i18n       = $schema[$entityShortName]['settings']['i18n'];
        $tblName    = $schema[$entityShortName]['settings']['dbname'];
        $dbFields   = array();

        /*
         * Vorher kam die Spaltenauswahl aus `showInList` — der Listenposition der
         * gelöschten Oberfläche. Sie ist mit den UI-Annotationen entfallen; die Route
         * liefert jetzt alle skalaren Eigenschaften. Für Clients ist das additiv.
         */
        foreach($schema[$entityShortName]['properties'] as $propName => $propConfig){

            switch($propConfig['type']){
                case 'multijoin':
                case 'multifile':
                    break;
                case 'file':
                case 'join':
                    $fieldName = $propConfig['dbfield'] ?: $propName.'_id';
                    $dbFields[$fieldName] = array('propName' => $propName, 'propType' => $propConfig['type']);
                    break;
                default:
                    $dbFields[$propName] = array('propName' => $propName, 'propType' => $propConfig['type']);
                    break;
            }
        }


        unset($dbFields['id']);
        unset($dbFields['sorting']);
        unset($dbFields['parent_id']);

        $tblTreeName  = 'pim_tree';
        $joinI18NCond = '';

        if($i18n){
            $tblTreeName  = 'pim_i18n_tree';
            $joinI18NCond = "AND t.lang = e.lang AND t.lang = '$lang'";
        }

        /*
         * Spaltennamen quoten: Seit die Auswahl alle Eigenschaften umfasst, sind auch
         * Felder wie `groups` dabei — in MySQL 8 ein reserviertes Wort.
         */
        $columns = implode(',', array_map(
            function($field){ return '`'.$field.'`'; },
            array_keys($dbFields)
        ));

        $statement = "
            SELECT t.id, ".$columns.", t.sorting, t.parent_id 
            FROM $tblName e 
            INNER JOIN $tblTreeName t 
              on e.id = t.id $joinI18NCond
            ORDER BY t.parent_id, t.sorting ";

        // fetchAll() ist in DBAL 3 entfallen (009-005-0002).
        $records = $this->app['database']->fetchAllAssociative($statement);

        return $this->treeSort($records, $dbFields, null);
    }

    private function treeSort($records, $dbFields, $parent_id): array
    {

        $items = array_filter($records, function($record) use ($parent_id) { return $parent_id ? $record['parent_id'] == $parent_id : empty($record['parent_id']); });

        $tree = array();

        foreach($items as $item){

            foreach($item as $dbField => $value){
				if(isset($dbFields[$dbField])) {
					$dbConfig = $dbFields[$dbField];
					switch($dbConfig['propType']){
						case 'join':
						case 'file':
							$item[$dbConfig['propName']] = array('id' => $value);
							unset($item[$dbField]);
							break;
						case 'integer':
							$item[$dbField] = intval($value);
							break;
					}
				}
            }

            $item['parent'] = array('id' => $item['parent_id']);
            unset($item['parent_id']);
            $item['sorting'] = intval($item['sorting']);
            $item['childs'] = $this->treeSort($records, $dbFields, $item['id']);

            $tree[]         = $item;
        }

        return $tree;
    }

    /**
     * @throws ContentflyException
     * @throws ReflectionException
     */
    public function getQuery(Array $params): array
    {

        $helper         = new Helper();
        $queryBuilder   = $this->database->createQueryBuilder();
        $paramCount     = 0;
        $schema         = $this->getSchema();

        if(!$this->app['auth.user']->getIsAdmin()){
            $group = $this->app['auth.user']->getGroup();
            if(!$group || $group->getApiQueryEnabled() != 'enabled'){
                throw new ContentflyException(Messages::contentfly_general_access_denied, 'api::query');
            }
        }

        if(!isset($params['select']) || !isset($params['from'])){
            throw new ContentflyException(Messages::contentfly_general_missing_params);
        }



        foreach($params as $method => $params){

            if($method == 'delete' || $method == 'insert'|| $method == 'update'){
                throw new ContentflyException(Messages::contentfly_general_invalid_params, $method);
            }

            $method = $method == 'where' ? 'andWhere' : $method;

            if(method_exists($queryBuilder, $method)){
                if(is_array($params)){
                    if($this->isIndexedArray($params)){

                        if(in_array($method, array('join', 'innerJoin', 'leftJoin', 'rightJoin'))){

                            $joins = array($params);

                            if(is_array($params[0])){
                                $joins = $params;
                            }

                            foreach($joins as $join){
                                if(count($join) != 4){
                                    throw new ContentflyException(Messages::contentfly_general_invalid_params, $method);
                                }

                                $entityName         = $join[1];
                                $entityAlias        = $join[2];
                                $entityShortName    = $helper->getShortEntityName($entityName);

                                if(!isset($schema[$entityShortName])) {
                                    foreach($schema as $entityNameFromSchema => $entityConfig){
                                        if($entityNameFromSchema == '_hash') continue;

                                        if(strtolower($entityConfig['settings']['dbname']) == strtolower($entityName)){
                                            $entityShortName = $entityNameFromSchema;
                                            break;
                                        }
                                    }
                                }

                                if(isset($schema[$entityShortName])) {

                                    if (!($permission = Permission::isReadable($this->app['auth.user'], $entityShortName))) {
                                        throw new ContentflyException(Messages::contentfly_general_access_denied, $entityShortName, Messages::contentfly_status_access_denied);
                                    }

                                    if ($permission == \Areanet\PIM\Entity\Permission::OWN) {
                                        $queryBuilder->andWhere("$entityAlias.usercreated_id = ? OR FIND_IN_SET(?, $entityAlias.users) > 0");
                                        $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                        $paramCount++;
                                        $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                        $paramCount++;
                                    } elseif ($permission == \Areanet\PIM\Entity\Permission::GROUP) {
                                        $group = $this->app['auth.user']->getGroup();
                                        if (!$group) {
                                            $queryBuilder->andWhere("$entityAlias.usercreated_id = ?");
                                            $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                        } else {
                                            $queryBuilder->andWhere("$entityAlias.usercreated_id = ? OR FIND_IN_SET(?, $entityAlias.users) > 0 OR FIND_IN_SET(?, $entityAlias.groups) > 0");
                                            $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                            $paramCount++;
                                            $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                            $paramCount++;
                                            $queryBuilder->setParameter($paramCount, $group->getId());
                                        }
                                        $paramCount++;
                                    }
                                }

                                $join[1] = $this->getTableName($entityName, $join[1]);

                                call_user_func_array(array($queryBuilder, $method), $join);
                            }


                        }else{
                            call_user_func_array(array($queryBuilder, $method), $params);
                        }



                    }else{
                        reset($params);
                        $queryKey       = key($params);
                        $queryParams    = $params[$queryKey];

                        if($method == 'from'){
                            //$queryKey     = entityName
                            //$queryParams  = entityAlias
                            $entityShortName = $helper->getShortEntityName($queryKey);

                            if(!isset($schema[$entityShortName])) {
                                foreach($schema as $entityNameFromSchema => $entityConfig){
                                    if($entityNameFromSchema == '_hash') continue;

                                    if(strtolower($entityConfig['settings']['dbname']) == strtolower($queryKey)){
                                        $entityShortName = $entityNameFromSchema;
                                        break;
                                    }
                                }
                            }

                            if(isset($schema[$entityShortName])) {
                                if (!($permission = Permission::isReadable($this->app['auth.user'], $entityShortName))) {
                                    throw new ContentflyException(Messages::contentfly_general_access_denied, $entityShortName, Messages::contentfly_status_access_denied);
                                }

                                if ($permission == \Areanet\PIM\Entity\Permission::OWN) {
                                    $queryBuilder->andWhere("$queryParams.userCreated_id = ? OR FIND_IN_SET(?, $queryParams.users) > 0");
                                    $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                    $paramCount++;
                                    $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                    $paramCount++;
                                } elseif ($permission == \Areanet\PIM\Entity\Permission::GROUP) {
                                    $group = $this->app['auth.user']->getGroup();
                                    if (!$group) {
                                        $queryBuilder->andWhere("$queryParams.usercreated_id = ?");
                                        $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                    } else {
                                        $queryBuilder->andWhere("$queryParams.usercreated_id = ? OR FIND_IN_SET(?, $queryParams.users) > 0 OR FIND_IN_SET(?, $queryParams.groups) > 0");
                                        $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                        $paramCount++;
                                        $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                        $paramCount++;
                                        $queryBuilder->setParameter($paramCount, $group->getId());
                                    }
                                    $paramCount++;
                                }
                            }

                            $queryKey = $this->getTableName($entityShortName, $queryKey);

                        }

                        if(is_array($queryParams)){
                            //array('where' => array('field1 = ? OR field2 = ?' => array('field1', 'field2'))
                            $queryBuilder->$method($queryKey);
                            foreach($queryParams as $queryParam){
                                $queryBuilder->setParameter($paramCount, $queryParam);
                                $paramCount++;
                            }
                        }elseif(str_contains($queryKey, '?')){
                            //array('where' => array('field1 = ?' => 'field2')
                            $queryBuilder->$method($queryKey);
                            $queryBuilder->setParameter($paramCount, $queryParams);
                            $paramCount++;
                        }else{
                            //array('where' => array('tableName' => 'tableAlias')

                            $queryBuilder->$method($queryKey, $queryParams);
                        }

                    }
                }else{
                    //array('from' => 'entity')
                    if($method == 'from'){
                        $entityShortName = $helper->getShortEntityName($params);

                        if(!isset($schema[$entityShortName])) {
                            foreach($schema as $entityNameFromSchema => $entityConfig){
                                if($entityNameFromSchema == '_hash') continue;
                                if(strtolower($entityConfig['settings']['dbname']) == strtolower($params)){
                                    $entityShortName = $entityNameFromSchema;
                                    break;
                                }
                            }
                        }

                        if(isset($schema[$entityShortName])) {

                            if (!($permission = Permission::isReadable($this->app['auth.user'], $entityShortName))) {
                                throw new ContentflyException(Messages::contentfly_general_access_denied, $entityShortName, Messages::contentfly_status_access_denied);
                            }

                            if ($permission == \Areanet\PIM\Entity\Permission::OWN) {
                                $queryBuilder->andWhere("userCreated_id = ? OR FIND_IN_SET(?, users) > 0");
                                $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                $paramCount++;
                                $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                $paramCount++;
                            } elseif ($permission == \Areanet\PIM\Entity\Permission::GROUP) {
                                $group = $this->app['auth.user']->getGroup();
                                if (!$group) {
                                    $queryBuilder->andWhere("userCreated_id = ?");
                                    $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                } else {
                                    $queryBuilder->andWhere("usercreated_id = ? OR FIND_IN_SET(?, users) > 0 OR FIND_IN_SET(?, groups) > 0");
                                    $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                    $paramCount++;
                                    $queryBuilder->setParameter($paramCount, $this->app['auth.user']->getId());
                                    $paramCount++;
                                    $queryBuilder->setParameter($paramCount, $group->getId());
                                }
                                $paramCount++;
                            }
                        }

                        $params = $this->getTableName($entityShortName, $params);
                    }



                    $queryBuilder->$method($params);
                }

            }
        }

        // executeQuery() statt execute() — letzteres ist in DBAL 3 @deprecated — und
        // fetchAllAssociative() statt fetchAll(), das am Result entfallen ist (009-005-0002).
        return $queryBuilder->executeQuery()->fetchAllAssociative();

    }

    protected function isIndexedArray(&$arr): bool
    {
        for (reset($arr); is_int(key($arr)); next($arr));
        return is_null(key($arr));
    }

}
