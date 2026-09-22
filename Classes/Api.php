<?php
namespace Areanet\PIM\Classes;


use Areanet\PIM\Classes\Type\UntypedColumn;
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
use Areanet\PIM\Classes\Metadata\MetadataReader;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappedSuperclass;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\Exception\ORMException;
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

        //Check permissions
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

        //Check whether translations already exist for sub-languages
        /*if($i18n){
            $mainLang = is_array(Adapter::getConfig()->APP_LANGUAGES) ? Adapter::getConfig()->APP_LANGUAGES[0] : null;

            if($object->getLang() != $mainLang){

                // executeStatement() instead of exec() (009-005-0002). exec() still exists in
                // DBAL 3, but as @deprecated — and Epic 009 builds deprecation-free.
                $this->em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0;');

                //$query = $this->em->createQuery("SELECT COUNT(e) FROM $entityFullName e WHERE e.id = :id");
                //$query->setParameter('id', $object->getId());

                //if($query->getSingleScalarResult() > 1){
                //    throw new ContentflyI18NException(Messages::contentfly_i18n_translations_exists, $entityShortName, $mainLang);
                //}
            }
        }*/

        //Update tree structure
        if($schema[$entityShortName]['settings']['type'] == 'tree') {
            $subObjects = $this->em->getRepository($entityFullName)->findBy(array('treeParent' => $object->getId()));
            if($subObjects){
                foreach($subObjects as $subObject){
                    $this->doDelete($entityShortName, $subObject->getId(), $this->app);
                }
            }
        }

        //Delete files
        //todo: move deletion of files out of the API
        if($entityShortName == 'PIM\\File') {
            $backend    = Backend::getInstance();

            $path   = $backend->getPath($object);
            foreach (new DirectoryIterator($path) as $fileInfo) {
                if ($fileInfo->isDot() || !$fileInfo->isFile()) continue;
                unlink($fileInfo->getPathname());
            }
            @rmdir($path);
        }

        //Logging
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


        //Delete OneJoins
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

        //Delete object
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

        if(!($permission = Permission::isWritable($this->app['auth.user'], $entityShortName))){
            throw new ContentflyException(Messages::contentfly_general_permission_denied, $entityShortName, Messages::contentfly_status_access_denied);
        }

        if(I18nPermission::isOnlyReadable($this->app, $entityShortName, $lang)){
            throw new ContentflyI18NException(Messages::contentfly_i18n_permission_denied, $entityShortName, $lang);
        }

        /*
         * A TRANSLATION BELONGS TO ITS RECORD (000-000-0059). An insert that carries the id of an
         * existing record creates a language variant of THAT record. The right on the entity
         * alone used to be enough: with OWN a user added translations to anyone's records.
         * Narrowed by the ownership of the existing record, like doUpdate() narrows a change.
         */
        if($schema[$entityShortName]['settings']['i18n'] && !empty($data['id'])){
            $tableName = $schema[$entityShortName]['settings']['dbname'];
            $existing  = $this->database->fetchAssociative(
                "SELECT usercreated_id, users, `groups` FROM `$tableName` WHERE id = ? LIMIT 1",
                array($data['id'])
            );

            if($existing && !$this->reachesRow($permission, $existing)){
                throw new ContentflyException(Messages::contentfly_general_permission_denied, "$entityShortName::{$data['id']}", Messages::contentfly_status_access_denied);
            }
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
                     * A REAL BUG, FOUND BY PHPSTAN (009-003-0002).
                     *
                     * This used to say `Messages::contentfly_general_record_already_exists`. That
                     * constant does not exist — it is called `…_ressource_already_exists`. So the
                     * line was not an error report but a fatal:
                     *
                     *     Undefined constant …Messages::contentfly_general_record_already_exists
                     *
                     * The caller got 500 instead of 409. `ConstraintApiTest` recorded that as the
                     * current state and attributed it to `000-000-0006` — the attribution was
                     * wrong, it was never down to the error chain.
                     *
                     * Now the same constant and the same status code as in the three other cases
                     * further down in doUpdate().
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

            /*
             * Without configured languages there is no main language (000-000-0064). The default
             * of APP_LANGUAGES is an EMPTY array, so is_array() was true and `[0]` raised
             * "Undefined array key 0". Without a main language there is nothing to inherit the
             * universal fields from — the translation carries exactly what was sent.
             */
            $mainLang = Adapter::getConfig()->APP_LANGUAGES[0] ?? null;
            if($mainLang !== null && $lang != $mainLang && !empty($data['id'])){
                $mainLangObject = $this->getSingle($entityShortName, $data['id'], null, $mainLang, true, null, null, true);
                if($mainLangObject){
                    foreach($schema[$entityShortName]['properties'] as $property => $propertyConfig){
                        if(!empty($propertyConfig['i18n_universal']) && $propertyConfig['type'] != 'multijoin' && $propertyConfig['type'] != 'multifile' && empty($data[$property])){
                            $getter = 'get'.ucfirst($property);
                            $setter = 'set'.ucfirst($property);
                            $value  = $mainLangObject->$getter();

                            // A join to an i18n entity is bound to the language being written, as
                            // JoinType::toDatabase() does on every other path (000-000-0025). Copied
                            // as it is, the translation of a tree node would point to the parent in
                            // the MAIN language — since the key of the relation carries `lang`,
                            // that is a different row.
                            if($value instanceof BaseI18n){
                                $value = $this->em->getReference(
                                    $this->em->getClassMetadata(get_class($value))->getName(),
                                    array('id' => $value->getId(), 'lang' => $lang)
                                );
                            }

                            $object->$setter($value);
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
         * Until 000-000-0007 this method collected its entities in a third way of its own: a
         * hard-wired inclusion list of File, User and Group, plus a pass over custom/Entity/
         * with a path that pointed out of the repo — which is why /api/all unconditionally
         * answered with HTTP 500.
         *
         * Now the same way as in getDeleted(), the other half of the sync contract: the
         * schema minus the same exclusion list. Previously getDeleted() reported deletions for
         * entities that getAll() never delivered — a client learned of the disappearance of
         * objects it had never received. For clients the change is additive: Tag, Option,
         * OptionGroup and the custom entities are added, nothing is dropped.
         *
         * Along the way it turned out that excludeFromSync had until then been checked
         * exclusively in getCount() — so the field affected the inventory statistics, never
         * the endpoint it is named after. The check now sits here as well.
         */
        $schema = $this->getSchema();

        /**
         * The second exclusion list is gone (000-000-0013).
         *
         * Here stood a hard-wired list — Folder, Token, Group, ThumbnailSetting, Permission,
         * Nav, NavItem, Log — in triplicate, in getAll(), getCount() and getDeleted(). It was
         * in no annotation and in no configuration: a project could not tell why an entity
         * is never synchronised.
         *
         * The affected entities now carry `@PIM\Config(excludeFromSync=true)`, each with its
         * justification on the class. That makes ONE mechanism instead of two, and it is in
         * the schema, which every client can read.
         *
         * Two entries of the old list were dead: `PIM\Token` is not in the schema (the entity
         * does not derive from Base), `PIM\PushToken` does not exist in the tree.
         *
         * `_hash` stays here: it is not an entity name but the schema hash. It cannot be
         * annotated, because there is no class it could be written on.
         */
        $helper   = new Helper();
        $entities = array();

        foreach(array_keys($schema) as $entityShortName){
            if($entityShortName === '_hash'){
                continue;
            }

            $entities[] = $helper->getFullEntityName($entityShortName);
        }

        /*
         * A SIZE IS A NAME, NOT A PATH (000-000-0075). Each size goes into the path of a file below,
         * and it came from the request unchecked: `../<other id>/<size>` read files from the
         * directory of a record the caller may not read. Only 'org' and the thumbnail sizes that
         * exist are taken — the same names /file/get accepts; anything else is left out.
         */
        if($filedata !== null){
            $knownSizes = array('org');
            foreach($this->em->getRepository('Areanet\PIM\Entity\ThumbnailSetting')->findAll() as $thumbnailSetting){
                $knownSizes[] = $thumbnailSetting->getAlias();
            }

            $filedata = array_values(array_filter((array) $filedata, fn ($size) => in_array($size, $knownSizes, true)));
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

            /*
             * 'Gelöscht' IS A LEGACY VALUE, NOT A NAME (014-003-0002).
             *
             * Contentfly 1.x wrote this German word into `pim_log.mode` when a token was deleted.
             * Existing projects carry such rows in their database, and sync clients must keep
             * receiving those deletions. The comparison therefore stays, by decision of
             * 2026-09-14; the English value written today is 'DEL'.
             */
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
         * What remains here is NOT a sync decision (000-000-0013).
         *
         * The shared list has become `excludeFromSync` on the entities and is checked
         * below. `PIM\File` stays here because files are counted separately in these
         * statistics — `filesCount` and `filesSize` further up. Listing them a second time
         * under `details` would be duplication.
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

            // COUNT INSTEAD OF FETCH (010-005-0001). This used to say `SELECT 1`, and counting
            // was done with `rowCount()` — one row per hit went over the connection and into
            // memory, only to be counted off. For a large table that is the difference
            // between a number and a data transfer.
            //
            // On top of that, `rowCount()` is not guaranteed for a READ query: DBAL says the
            // return value then depends on the driver. That it worked under MySQL was not a
            // contract. Finding from 009-005-0002.
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
                    // `groups` is reserved in MySQL 8 and needs the backticks unless it is qualified
                    // with a table alias — without them every GROUP user got 500 here (000-000-0072).
                    $tsQuery .= " AND (userCreated_id = ? OR FIND_IN_SET(?, `groups`) > 0)";
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

            // fetchAssoc() was removed in DBAL 3 (009-005-0002).
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
             * excludeFromSync — new here, see 000-000-0013.
             *
             * getDeleted() NEVER checked it: it only had its hard-wired list. getAll() and
             * getCount() have checked it since 000-000-0007 and since forever, respectively.
             * An entity that is excluded from the inventory but reports its deletions makes
             * no sense — a sync client would receive deletion notices for objects it never
             * received.
             */
            if(!empty($entityConfig['settings']['excludeFromSync'])){
                continue;
            }

            /*
             * Only entities the user may read (000-000-0061). The log used to report the
             * deletions of every entity — only ids, but ids of records the user may not know
             * exist. There is no owner to narrow by: the record is gone, the log row is all
             * that is left of it.
             */
            if(!Permission::isReadable($this->app['auth.user'], $entityName)){
                continue;
            }

            $query = "SELECT model_name, model_id FROM `pim_log` WHERE model_name = ? AND (mode = 'DEL' OR (mode = 'USERDEL' AND users = ?))";

            $params  = array($entityName, $this->app['auth.user']->getId());
            /**
             * `>=` instead of `>` — the boundary second is included (000-000-0013).
             *
             * `pim_log.created` is a datetime with second resolution. A lifecycle that runs
             * within the same second — the normal case with one API call per step — leaves
             * rows with identical timestamps. With `>` a sync client loses every deletion
             * that happened in the same second as the one whose timestamp it remembered: it
             * is not greater, so it never arrives.
             *
             * `>=` instead delivers the boundary second again. Reporting a deletion twice has
             * no consequences — the client deletes something that is already gone. Losing a
             * deletion does: the object stays on the client forever, and nothing ever
             * points to it.
             *
             * getAll() has always filtered with `modified >= :lastModified`. So the two halves
             * of the same synchronisation lay on different sides of the boundary.
             *
             * The real solution would be a higher resolution or a monotonically increasing
             * sequence. Both need a column and therefore a migration for every existing
             * project; that belongs to the kernel switch and not here.
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

            // fetchAll() was removed in DBAL 3 (009-005-0002).
            if(($deletedObjects = $this->app['database']->fetchAllAssociative($query, $params))){
                $data   = array_merge($data, $deletedObjects);
            }

        }

        return $data;
    }

    public function getExtendedSchema(): array
    {
        /*
         * THE frontend BLOCK HAS SHRUNK FROM SEVEN TO TWO KEYS (000-000-0010).
         *
         * Dropped are customLogo, formImageSquarePreview, title, welcome and
         * login_redirect. All five described properties of the PIM interface that Epic
         * 012 removed — the schema kept advertising them.
         *
         * The two remaining ones are not an interface matter:
         *
         *   customNavigation  reads the entities PIM\Nav and PIM\NavItem. Both exist,
         *                     they belong to the data model and the suite touches them.
         *   languages         comes from APP_LANGUAGES and determines the main language
         *                     (bootstrap.php derives APP_CMS_MAIN_LANG from it).
         *
         * The key name "frontend" stays nonetheless. Renaming it would be a second break
         * for every client that reads it — and one with no benefit.
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

        /*
         * Field names and the direction come from the request and go into DQL as text, so each
         * must name a property of the entity (000-000-0063). They used to go in unchecked — DQL
         * injection. An unknown name is rejected, not dropped: a sort or grouping the client
         * asked for must not silently disappear.
         */
        if($order !== null){
            foreach($order as $orderBy => $orderSort){
                $this->assertProperty($entityShortName, $orderBy);

                $direction = strtoupper((string) $orderSort);
                if(!in_array($direction, array('ASC', 'DESC'), true)){
                    throw new ContentflyException(Messages::contentfly_general_invalid_sort_direction, "$entityShortName::$orderBy", Messages::contentfly_status_bad_request);
                }

                $queryBuilder->addOrderBy($entityNameAlias.'.'.$orderBy, $direction);
            }
        }else{
            $queryBuilder->orderBy($entityNameAlias.'.id', 'DESC');
        }

        if($groupBy){
            $this->assertProperty($entityShortName, $groupBy);
            $queryBuilder->groupBy($entityNameAlias.".".$groupBy);
        }

        $validProperties            = array();
        if(count($properties)){

            foreach($properties as $name){

                if(!isset($schema[$entityShortName]['properties'][$name]) || in_array($name, array('id', 'lang'))){
                    continue;
                }

                $config = $schema[$entityShortName]['properties'][$name];

                // Collections have no column to select. `permissions` was missing here, and
                // `properties: ["permissions"]` on PIM\Group answered 500 (000-000-0067).
                if(in_array($config['type'], array('multijoin', 'multifile', 'checkbox', 'permissions'))){
                    continue;
                }

                // Joined below, like `join` — see the note on `onejoin` there.
                if(in_array($config['type'], array('join', 'file', 'radio', 'onejoin'))){
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

        /*
         * `onejoin` BELONGS IN THIS LIST (000-000-0067).
         *
         * The query below runs with HINT_FORCE_PARTIAL_LOAD as soon as one relation is joined —
         * and every entity has one, `user`. Under that hint Doctrine creates no proxy for a
         * relation the query does not join, so a one-to-one field came back as `null` in every
         * list, while `/api/single` returned it. Nobody noticed because no entity had such a
         * field until the template got `Core\ExampleRelations`.
         */
        foreach ($schema[$entityShortName]['properties'] as $field => $config) {
            if (count($properties) && !in_array($field, $properties)) continue;

            if(in_array($config['type'], array('radio', 'join', 'file', 'onejoin'), true)){
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

            // 'export' and 'extended' were dropped with 000-000-0012. Both stood here without
            // any endpoint ever checking them — see the class comment of
            // Areanet\PIM\Classes\Permission.
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

            $metadata = new MetadataReader();

            // See above: 'export' and 'extended' were dropped with 000-000-0012.
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

            $classAnnotations = $metadata->forClass($reflect);

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


                $propertyAnnotations = $metadata->forProperty($reflectionProperty);

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
                 * A FIELD WITHOUT A MATCHING TYPE NO LONGER SILENTLY DROPS OUT OF THE SCHEMA
                 * (000-000-0017).
                 *
                 * If none of the registered types matched, $properties simply stayed unset for
                 * this property: no entry, no warning, no hint. Reading did not return the
                 * field, writing failed with contentfly_general_unknown_property — and nobody
                 * learned why. The custom/ template demonstrated exactly this case with a json
                 * field.
                 *
                 * NOTHING is thrown: a project with an exotic column type could otherwise no
                 * longer build its schema after an update. A warning lands in the log, and the
                 * suite sets failOnWarning — there it shows up immediately, without upsetting
                 * anything in production.
                 */
                if (empty($properties[$prop->getName()]) && isset($allPropertyAnnotations['Doctrine\\ORM\\Mapping\\Column'])) {
                    // Binary columns are left out without a warning (000-000-0046), see UntypedColumn.
                    UntypedColumn::report(
                        $entityName,
                        $prop->getName(),
                        $allPropertyAnnotations['Doctrine\\ORM\\Mapping\\Column']->type
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
        /*
         * ONLY THIS ENTITY, NOT THE WHOLE ENTITY MANAGER (000-000-0078). This said
         * `$this->em->clear($entityFullName)` — the partial clear of ORM 2. ORM 3 (epic 010) dropped
         * the argument, PHP ignores the extra one, and everything was cleared, the logged-in user
         * included. doInsert() calls this before it copies the universal fields of a translation,
         * and its flush then found `userCreated` pointing to a user it did not know: with
         * APP_LANGUAGES set, no translation of an existing record could be added. Detaching the
         * managed objects of this entity is what the ORM 2 call did.
         */
        if($clearEM){
            $rootEntityName = $this->em->getClassMetadata($entityFullName)->rootEntityName;

            foreach($this->em->getUnitOfWork()->getIdentityMap()[$rootEntityName] ?? array() as $managed){
                $this->em->detach($managed);
            }
        }

        $queryBuilder
            ->select($entityNameAlias)
            ->from($entityFullName, $entityNameAlias);


        if($id){
            $queryBuilder
                ->where("$entityNameAlias.id = :id")
                ->setParameter('id', $id);
        }elseif($where){
            foreach($where as $field => $value){
                // The key is a field name that goes into DQL as text (000-000-0063). Rejected
                // when unknown, not dropped: without the filter a DIFFERENT record comes back.
                $this->assertProperty($entityShortName, $field);

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
         * Not found means null (000-000-0006).
         *
         * UNTIL NOW THIS METHOD RETURNED A JsonResponse — a finished HTTP response from a
         * class that is not a controller. Every internal caller checks with `if(!$object)`,
         * and a JsonResponse is truthy. So the check came to nothing, and the code after it
         * carried on working with the response as if it were the object:
         *
         *   Helper::getUsersRemoved(): Argument #1 must be of type ...Entity\Base,
         *   ...HttpFoundation\JsonResponse given, called in Api.php on line 488
         *
         * That was the reason an unknown id arrived via the API as 500, although doUpdate()
         * and doDelete() each provide a ContentflyException with 404 — they were never
         * reached. A TypeError is not an Exception, and Silex's error chain only accepts
         * Exceptions; that is why it fell through to the global handler.
         *
         * `/api/single` even output the response: the caller got 200 and as the body
         * `data: {"headers": {}}` — the JsonResponse, run through json_encode.
         */
        if (!$object) {
            return null;
        }

        if($compareToLang && $compareToLang != $lang) {
            if(!$loadJoinedLang) {
                //Edit existing translated record
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
                //Translate record anew

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

    /**
     * A field name from the request must name a property of the entity before it goes into DQL
     * as text (000-000-0063).
     *
     * @throws ContentflyException
     */
    protected function assertProperty(string $entityShortName, mixed $field): void
    {
        if(!is_string($field) || !isset($this->app['schema'][$entityShortName]['properties'][$field])){
            throw new ContentflyException(Messages::contentfly_general_unknown_property, $entityShortName.'::'.(is_scalar($field) ? $field : gettype($field)), Messages::contentfly_status_bad_request);
        }
    }

    /**
     * Whether a permission level reaches a raw table row — the rule doUpdate() applies to an
     * object: OWN reaches rows the user created or is listed in `users`, GROUP additionally rows
     * listed for the user's group, ALL reaches every row (000-000-0059).
     *
     * @param array{usercreated_id: ?string, users: ?string, groups: ?string} $row
     */
    protected function reachesRow(int $permission, array $row): bool
    {
        $user = $this->app['auth.user'];
        $own  = $row['usercreated_id'] === $user->getId()
            || in_array($user->getId(), explode(',', (string) $row['users']), true);

        if($permission == \Areanet\PIM\Entity\Permission::OWN){
            return $own;
        }

        if($permission == \Areanet\PIM\Entity\Permission::GROUP){
            $group = $user->getGroup();

            return $own || ($group && in_array($group->getId(), explode(',', (string) $row['groups']), true));
        }

        return true;
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

        if(!($permission = Permission::isReadable($this->app['auth.user'], $entityName))){
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

        /*
         * Narrowed by OWN/GROUP like getCount() (000-000-0059). Only numbers flow here, no
         * content — but they used to count every owner's records, telling a user how many
         * records exist that the user may not see.
         */
        $userId = $this->app['auth.user']->getId();
        if($permission == \Areanet\PIM\Entity\Permission::OWN){
            $queryBuilder->andWhere('(usercreated_id = :userId OR FIND_IN_SET(:userId, users) > 0)')
                ->setParameter('userId', $userId);
        }elseif($permission == \Areanet\PIM\Entity\Permission::GROUP){
            $group = $this->app['auth.user']->getGroup();
            if(!$group){
                $queryBuilder->andWhere('usercreated_id = :userId');
            }else{
                $queryBuilder->andWhere('(usercreated_id = :userId OR FIND_IN_SET(:userId, users) > 0 OR FIND_IN_SET(:groupId, `groups`) > 0)')
                    ->setParameter('groupId', $group->getId());
            }
            $queryBuilder->setParameter('userId', $userId);
        }

        // executeQuery() instead of execute() — the latter is @deprecated in DBAL 3 — and
        // fetchAllAssociative() instead of fetchAll(), which was removed from the Result
        // (009-005-0002).
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

        /*
         * The read right, as in getList() (000-000-0061). This route used to check only THAT
         * someone is logged in: a user without any read right got the full tree.
         *
         * Narrowed by OWN/GROUP on every level of the recursion. A child is only fetched
         * through its parent, so a node below a hidden parent stays hidden — getTree2() does
         * the same.
         */
        if(!($permission = Permission::isReadable($this->app['auth.user'], $entityShortName))){
            throw new ContentflyException(Messages::contentfly_general_permission_denied, $entityShortName, Messages::contentfly_status_access_denied);
        }

        $i18n               = $schema[$entityShortName]['settings']['i18n'];

        $queryBuilder = $this->em->createQueryBuilder();
        $queryBuilder->from($entityFullName, $entityNameAlias)
            ->where("$entityNameAlias.isIntern = false")
            ->orderBy($entityNameAlias.'.sorting', 'ASC');

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

        /*
         * Only names of properties go into the partial select (000-000-0063); they used to go
         * into DQL unchecked. Unknown ones are dropped — exactly what getList() has always done
         * with its `properties`.
         */
        $properties = array_values(array_filter($properties, function($name) use ($schema, $entityShortName){
            return is_string($name) && isset($schema[$entityShortName]['properties'][$name]);
        }));

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

        // The read right, as in getTree() (000-000-0061).
        if(!($permission = Permission::isReadable($this->app['auth.user'], $entityShortName))){
            throw new ContentflyException(Messages::contentfly_general_permission_denied, $entityShortName, Messages::contentfly_status_access_denied);
        }

        $i18n       = $schema[$entityShortName]['settings']['i18n'];
        $tblName    = $schema[$entityShortName]['settings']['dbname'];
        $dbFields   = array();

        /*
         * Previously the column selection came from `showInList` — the list position of the
         * deleted interface. It was dropped together with the UI annotations; the route now
         * delivers all scalar properties. For clients this is additive.
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
        $params       = array();

        /*
         * `lang` is BOUND, not written into the statement (000-000-0062). It comes unchanged
         * from the request body of /api/tree2; it used to be placed between quotes here, which
         * made it an SQL injection for every logged-in user.
         */
        if($i18n){
            $tblTreeName  = 'pim_i18n_tree';
            $joinI18NCond = 'AND t.lang = e.lang AND t.lang = ?';
            $params[]     = $lang;
        }

        /*
         * Quote column names: since the selection covers all properties, fields such as
         * `groups` are included too — a reserved word in MySQL 8.
         */
        $columns = implode(',', array_map(
            function($field){ return '`'.$field.'`'; },
            array_keys($dbFields)
        ));

        /*
         * Narrowed by OWN/GROUP like getTree() (000-000-0061). The owner columns live in the
         * tree table, not in the entity's own one. treeSort() builds the tree from the root
         * down, so a node whose parent is filtered out is left out as well — the same result
         * as getTree(), which reaches children only through their parent.
         */
        $where = '';
        if($permission == \Areanet\PIM\Entity\Permission::OWN){
            $where    = 'WHERE (t.usercreated_id = ? OR FIND_IN_SET(?, t.users) > 0)';
            $params[] = $this->app['auth.user']->getId();
            $params[] = $this->app['auth.user']->getId();
        }elseif($permission == \Areanet\PIM\Entity\Permission::GROUP){
            $group = $this->app['auth.user']->getGroup();
            if(!$group){
                $where    = 'WHERE t.usercreated_id = ?';
                $params[] = $this->app['auth.user']->getId();
            }else{
                $where    = 'WHERE (t.usercreated_id = ? OR FIND_IN_SET(?, t.users) > 0 OR FIND_IN_SET(?, t.`groups`) > 0)';
                $params[] = $this->app['auth.user']->getId();
                $params[] = $this->app['auth.user']->getId();
                $params[] = $group->getId();
            }
        }

        $statement = "
            SELECT t.id, ".$columns.", t.sorting, t.parent_id 
            FROM $tblName e 
            INNER JOIN $tblTreeName t 
              on e.id = t.id $joinI18NCond
            $where
            ORDER BY t.parent_id, t.sorting ";

        // fetchAll() was removed in DBAL 3 (009-005-0002).
        $records = $this->app['database']->fetchAllAssociative($statement, $params);

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
                                    $queryBuilder->andWhere("usercreated_id = ? OR FIND_IN_SET(?, users) > 0 OR FIND_IN_SET(?, `groups`) > 0"); // 000-000-0072, see getCount()
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

        // executeQuery() instead of execute() — the latter is @deprecated in DBAL 3 — and
        // fetchAllAssociative() instead of fetchAll(), which was removed from the Result
        // (009-005-0002).
        return $queryBuilder->executeQuery()->fetchAllAssociative();

    }

    protected function isIndexedArray(&$arr): bool
    {
        for (reset($arr); is_int(key($arr)); next($arr));
        return is_null(key($arr));
    }

}
