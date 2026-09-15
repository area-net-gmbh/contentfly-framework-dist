<?php
namespace Areanet\PIM\Controller;

use Areanet\PIM\Classes\Annotations\ManyToMany;
use Areanet\PIM\Classes\Api;
use \Areanet\PIM\Classes\Config;
use Areanet\PIM\Classes\Controller\BaseController;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Exceptions\Entity\EntityDuplicateException;
use Areanet\PIM\Classes\Exceptions\Entity\EntityNotFoundException;
use Areanet\PIM\Classes\Exceptions\File\FileExistsException;
use Areanet\PIM\Classes\File\Backend;
use Areanet\PIM\Classes\File\Backend\FileSystem;
use Areanet\PIM\Classes\File\Processing;
use Areanet\PIM\Classes\File\Processing\Standard;
use Areanet\PIM\Classes\Helper;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Classes\Permission;
use Areanet\PIM\Entity\Base;
use Areanet\PIM\Entity\BaseSortable;
use Areanet\PIM\Entity\BaseTree;
use Areanet\PIM\Entity\File;
use Areanet\PIM\Entity\Log;
use Areanet\PIM\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\Id\AssignedGenerator;
use Doctrine\ORM\Query;

use Symfony\Component\HttpFoundation\File\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;


class ApiController extends BaseController
{
    protected $_MIMETYPES = array(
        'images' => array('image/jpeg', 'image/png', 'image/gif'),
        'pdf' => array('application/pdf')
    );

    /**
     * @apiVersion 1.4.2
     * @api {post} /api/all all
     * @apiName All
     * @apiGroup Objects
     * @apiDeprecated
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiDescription Returns all objects of all entities
     *
     * @apiParam {String} [lastModified="yyyymmdd hh:mm:ii"] Only the objects that have been changed since lastModified are returned.
     * @apiParam {Boolean} [flatten="false"] For joins, returns only the IDs and not the complete objects
     * @apiParamExample {json} Request example:
     *     {
     *      "lastModified": "2016-02-20 15:30:22"
     *      }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "message": "allAction",
     *       "lastModified": "2016-02-21 12:20:00"
     *       "data:" {
     *          "News": [
     *              {
     *                  "id": 1,
     *                  "isHidden": false,
     *                  "isDeleted": false,
     *                  "title": "A news item"
     *              },
     *              ...
     *          },
     *          "EntityXYZ": [
     *              {...},
     *              {...},
     *              ...
     *          ]
     *      }
     *     }
     */
    public function allAction(Request $request)
    {
        $timestamp              = ($request->request->all()['lastModified'] ?? null);
        $filedata               = ($request->request->all()['filedata'] ?? null);
        $flatten                = ($request->request->all()['flatten'] ?? false);

        $lastModified = null;
        if(!empty($timestamp)) {
            try {
                $lastModified = new \Datetime($timestamp);
            } catch (\Exception $e) {

            }
        }

        $api = new Api($this->app, $request);
        $all = $api->getAll($lastModified, $flatten, $filedata);

        $currentDate = new \Datetime();

        return $this->renderResponse(array('lastModified' => $currentDate->format('Y-m-d H:i:s'),  'data' => $all), count($all) ? 200 : 204);
    }

    /**
     * @apiVersion 1.4.2
     * @api {get} /api/config config
     * @apiName Config
     * @apiGroup Settings
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiDescription Basic, publicly accessible configuration, e.g. for the login page
     *
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "devmode": false,
     *       "version": "1.4.0"
     *       "data:" {
     *         ...
     *       }
     *     }
     */
    public function configAction()
    {
        /*
         * THE frontend KEY HAS BEEN DROPPED (000-000-0010).
         *
         * It contained exactly one entry, customLogo — a property of the PIM UI that Epic 012
         * removed. That would be a leftover anywhere; here it was more: /api/config is the ONLY
         * route of the ApiControllerProvider without ->before(checkAuth) and therefore the public
         * part of the API. Whatever it reveals, anyone can see without a token.
         *
         * It was not left standing empty: a key that no longer carries anything invites people to
         * put something back into it. The removal is recorded as a breaking change in
         * an_project/docs/breaking-changes.md.
         */
        return $this->renderResponse(array('devmode' => Config\Adapter::getConfig()->APP_DEBUG, 'version' => APP_VERSION.'/'.CUSTOM_VERSION));
    }

    /**
     * @apiVersion 1.4.2
     * @api {post} /api/count count
     * @apiName Count
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String/Array} [lastModified="yyyymmdd hh:mm:ii"] Only the objects that have been changed since lastModified are returned.
     * @apiParamExample {json} Request example with a global timestamp:
     *     {
     *      "lastModified": "2016-02-20 15:30:22"
     *      }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "dataCount": 12345,
     *       "filesCount": 234
     *       "filesSize": 1234355
     *     }
     */

    /**
     * @apiVersion 1.5.2
     * @api {post} /api/count count
     * @apiName Count
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String/Array} [lastModified="yyyymmdd hh:mm:ii"] Only the objects that have been changed since lastModified are returned.
     * @apiParam {String} [entity = null] Only the count of the specified entity is returned
     * @apiParamExample {json} Request example with a global timestamp:
     *     {
     *      "lastModified": "2016-02-20 15:30:22"
     *      }
     * @apiParamExample {json} Request example with a timestamp per entity:
     *     {
     *      "lastModified": {
     *          "Entity1" : "2016-02-20 15:30:22",
     *          "Entity2" : "2016-02-20 15:30:22"
     *      }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "dataCount": 12345,
     *       "filesCount": 234
     *       "filesSize": 1234355
     *     }
     */
    public function countAction(Request $request){
        $api            = new Api($this->app, $request);
        $lastModified   = ($request->request->all()["lastModified"] ?? null);
        $entity         = ($request->request->all()["entity"] ?? null);
        $data           = $api->getCount($lastModified, $entity);

        $currentDate    = new \Datetime();

        return $this->renderResponse(array('ts' => $currentDate->format('Y-m-d H:i:s'), 'data' => $data));
    }

    /**
     * @apiVersion 1.4.2
     * @api {post} /api/delete delete
     * @apiName Delete
     * @apiDescription API endpoint for deleting an object of an entity.
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} entity Entity to delete
     * @apiParam {Integer} id Object ID to delete
     * @apiParam {String} [lang = null] Language code for language-dependent entities (I18N)
     * @apiParamExample {json} Request example:
     *     {
     *      "entity": "News",
     *      "id": 12
     *      }
     */
    public function deleteAction(Request $request)
    {

        $helper              = new Helper();
        $entityShortName     = $helper->getShortEntityName(($request->request->all()['entity'] ?? null));
        $id                  = ($request->request->all()['id'] ?? null);
        $lang                = ($request->request->all()['lang'] ?? null);

        $event = new \Areanet\PIM\Classes\Event();
        $event->setParam('entity',  $entityShortName);
        $event->setParam('request', $request);
        $event->setParam('id',      $id);
        $event->setParam('lang',    $lang);
        $event->setParam('user',    $this->app['auth.user']);
        $event->setParam('app',     $this->app);

        $this->app['dispatcher']->dispatch($event, 'pim.entity.before.delete');

        $api = new Api($this->app, $request);
        $api->doDelete($entityShortName, $id, $lang);

        $event = new \Areanet\PIM\Classes\Event();
        $event->setParam('entity',  $entityShortName);
        $event->setParam('request', $request);
        $event->setParam('id',      $id);
        $event->setParam('lang',    $lang);
        $event->setParam('user',    $this->app['auth.user']);
        $event->setParam('app',     $this->app);
        $this->app['dispatcher']->dispatch($event, 'pim.entity.after.delete');

        $currentDate = new \Datetime();

        return $this->renderResponse(array('ts' => $currentDate->format('Y-m-d H:i:s'), 'id' => $id));
    }

    /**
     * @apiVersion 1.4.2
     * @api {post} /api/deleted deleted
     * @apiName Deleted
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String/Array} [lastModified="yyyymmdd hh:mm:ii"] Only the objects that have been deleted since lastModified are returned.
     * @apiParamExample {json} Request example with a global timestamp:
     *     {
     *      "lastModified": "2016-02-20 15:30:22"
     *      }
     * @apiParamExample {json} Request example with a timestamp per entity:
     *     {
     *      "lastModified": {
     *          "Entity1" : "2016-02-20 15:30:22",
     *          "Entity2" : "2016-02-20 15:30:22"
     *      }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     [
     *       {"entity_name": "Entity1", "id": "...."}
     *     ]
     */
    public function deletedAction(Request $request){
        $api            = new Api($this->app, $request);
        $lastModified   = ($request->request->all()["lastModified"] ?? null);
        $data           = $api->getDeleted($lastModified);
        $currentDate    = new \Datetime();
        return $this->renderResponse(array('ts' => $currentDate->format('Y-m-d H:i:s'), 'data' => $data));
    }


    /**
     * @apiVersion 1.4.2
     * @api {post} /api/insert insert
     * @apiName Insert
     * @apiDescription API endpoint for adding a new object of an entity.
     *
     * Date fields should be transmitted in ISO 8601 format.
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} entity Entity to insert
     * @apiParam {Object} data Data of the object, depending on the entity
     * @apiParam {String} [lang = null] Language code for language-dependent entities (I18N)
     * @apiParamExample {json} Request example:
     *     {
     *      "entity": "News",
     *      "data": {
     *          "title": "A new news item",
     *          "subtitle: "Subtitle of the new news item",
     *          "date": "2016-02-18 15:30:00",
     *          // Join 1:n
     *          "category": {
     *              "id": 1
     *          },
     *          // Date in the format yyyy-mm-dd hh:ii:ss
     *          "active_from": "2016-02-18 15:30:00",
     *          // Multijoin n:m
     *          "cross_selling": [
     *              {
     *                  "id": 2
     *              },
     *              {
     *                  "id": 6
     *              }
     *           ]
     *      }
     * @apiError 500 An object with the same UNIQUE-INDEX already exists
     * @apiError 501 Unknown server error
     */
    public function insertAction(Request $request)
    {

        $helper              = new Helper();
        $entityShortName     = $helper->getShortEntityName(($request->request->all()['entity'] ?? null));

        $data                = ($request->request->all()['data'] ?? null);
        $lang                = ($request->request->all()['lang'] ?? null);

        $event = new \Areanet\PIM\Classes\Event();
        $event->setParam('entity',  $entityShortName);
        $event->setParam('request', $request);
        $event->setParam('user',    $this->app['auth.user']);
        $event->setParam('data',    $data);
        $event->setParam('lang',    $lang);
        $event->setParam('app',     $this->app);
        $this->app['dispatcher']->dispatch($event, 'pim.entity.before.insert');

        $data = $event->getParam('data');

        $api = new Api($this->app, $request);
        $object = $api->doInsert($entityShortName, $data, $lang);


        $event = new \Areanet\PIM\Classes\Event();
        $event->setParam('entity',  $entityShortName);
        $event->setParam('request', $request);
        $event->setParam('user',    $this->app['auth.user']);
        $event->setParam('object',  $object);
        $event->setParam('lang',    $lang);
        $event->setParam('app',     $this->app);
        $this->app['dispatcher']->dispatch($event, 'pim.entity.after.insert');

        $currentDate    = new \Datetime();

        return $this->renderResponse(array('ts' => $currentDate->format('Y-m-d H:i:s'), 'id' => $object->getId(), "data" => $object->toValueObject($this->app, $entityShortName, true)));
    }

    /**
     * @apiVersion 1.4.2
     * @api {post} /api/list list
     * @apiName List
     * @apiDescription API endpoint for retrieving objects of an entity.
     *
     * The data is returned in JSON format based on the Doctrine ORM. Joins (1:n) and multijoins
     * (n:m) are converted automatically and returned as sub-objects. With many objects that have
     * joins/multijoins, this can lead to performance problems. The parameter flatten provides a
     * remedy.
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} entity Entity to read
     * @apiParam {Array} [properties] Returns only the specified properties/fields, otherwise all properties are loaded (performance!)<code>['field1', 'field2', ...]</code>
     * @apiParam {Object} [order="{'id': 'DESC'}"] Sort order: <code>{'date': 'ASC/DESC',...}</code>
     * @apiParam {String} [groupBy] Grouping of the result by property
     * @apiParam {Object} [where] Condition; multiple fields are combined with AND: <code>{'title': 'test', 'desc': 'foo',...}</code>
     * @apiParam {Boolean} [count] Return only the number of objects
     * @apiParam {Integer} [currentPage] Current page for pagination
     * @apiParam {Integer} [itemsPerPage="Config::FRONTEND_ITEMS_PER_PAGE"] Number of objects per page for pagination
     * @apiParam {Boolean} [flatten="false"] For joins, returns only the IDs and not the complete objects
     * @apiParamExample {json} Request example with a where query:
     *     {
     *      "entity": "News",
     *      "currentPage": 1,
     *      "order": {
     *          "date": "DESC"
     *       },
     *      "where": {
     *          "title": "foo",
     *          "isHidden": false
     *      },
     *      "properties": ["id", "title"]
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "message": "listAction",
     *       "lastModified": "2016-02-21 12:20:00"
     *       "itemsPerPage": 15,
     *       "totalItems": 200,
     *       "data:" [
     *          {
     *              "id": 1,
     *              "isHidden": false,
     *              "isDeleted": false,
     *              "title": "A news item"
     *          },
     *          {...},
     *          ...
     *      ]
     *   }
     * @apiError 404 No entries available
     */

     /**
     * @apiVersion 1.5.0
     * @api {post} /api/list list
     * @apiName List
     * @apiDescription API endpoint for retrieving objects of an entity.
     *
     * The data is returned in JSON format based on the Doctrine ORM. Joins (1:n) and multijoins
     * (n:m) are converted automatically and returned as sub-objects. With many objects that have
     * joins/multijoins, this can lead to performance problems. The parameter flatten provides a
     * remedy.
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} entity Entity to read
     * @apiParam {Array} [properties] Returns only the specified properties/fields, otherwise all properties are loaded (performance!)<code>['field1', 'field2', ...]</code>
     * @apiParam {Object} [order="{'id': 'DESC'}"] Sort order: <code>{'date': 'ASC/DESC',...}</code>
     * @apiParam {String} [groupBy] Grouping of the result by property
     * @apiParam {Object} [where] Condition; multiple fields are combined with AND: <code>{'title': 'test', 'desc': 'foo',...}</code>
     * @apiParam {Boolean} [count] Return only the number of objects
     * @apiParam {Integer} [currentPage] Current page for pagination
     * @apiParam {Integer} [itemsPerPage="Config::FRONTEND_ITEMS_PER_PAGE"] Number of objects per page for pagination
     * @apiParam {Boolean} [flatten="false"] For joins, returns only the IDs and not the complete objects
     * @apiParam {String} [lastModified="yyyymmdd hh:mm:ii"] Only the objects that have been changed since lastModified are returned.
     * @apiParam {String} [lang = null] Language code for language-dependent entities (I18N)
     * @apiParam {String} [untranslatedLang = null ]Language code for which records that have not yet been translated are set for lang. The parameter lang must be set. (I18N)
     * @apiParamExample {json} Request example with a where query:
     *     {
     *      "entity": "News",
     *      "currentPage": 1,
     *      "order": {
     *          "date": "DESC"
     *       },
     *      "where": {
     *          "title": "foo",
     *          "isHidden": false
     *      },
     *      "properties": ["id", "title"]
     * @apiParamExample {json} Request example: recently updated objects
     *     {
     *      "entity": "News",
     *      "lastModified": "2016-02-20 15:30:22"
     *     }
     * @apiParamExample {json} Request example: show all English records that have not yet been translated into German
     *     {
     *      "entity": "News",
     *      "lang": "de",
     *      "untranslatedLang": "en"
     *     }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "message": "listAction",
     *       "lastModified": "2016-02-21 12:20:00"
     *       "itemsPerPage": 15,
     *       "totalItems": 200,
     *       "data:" [
     *          {
     *              "id": 1,
     *              "isHidden": false,
     *              "isDeleted": false,
     *              "title": "A news item"
     *          },
     *          {...},
     *          ...
     *      ]
     *   }
     * @apiError 404 No entries available
     */
    public function listAction(Request $request)
    {

        $entityName             = ($request->request->all()['entity'] ?? null);
        $groupBy                = ($request->request->all()['groupBy'] ?? false);
        $doCount                = ($request->request->all()['count'] ?? false);
        $order                  = ($request->request->all()['order'] ?? null);
        $where                  = ($request->request->all()['where'] ?? null);
        $currentPage            = ($request->request->all()['currentPage'] ?? null);
        $itemsPerPage           = ($request->request->all()['itemsPerPage'] ?? Config\Adapter::getConfig()->FRONTEND_ITEMS_PER_PAGE);
        $flatten                = ($request->request->all()['flatten'] ?? false);
        $lastModified           = ($request->request->all()['lastModified'] ?? null);
        $lang                   = ($request->request->all()['lang'] ?? null);
        $untranslatedLang       = ($request->request->all()['untranslatedLang'] ?? null);

        $properties             = ($request->request->all()['properties'] ?? array());
        $properties             = is_array($properties) ? $properties : array();

        $api        = new Api($this->app, $request);

        $data = $api->getList($entityName, $where, $order, $groupBy, $properties, $lastModified, $flatten, $currentPage, $itemsPerPage, $lang, $untranslatedLang);

        /**
         * An empty set is a result, not an error (000-000-0014).
         *
         * THIS USED TO SAY `return new JsonResponse(array('message' => "Not found"), 404)` — an
         * eighth response shape that had nothing in common with any of the other seven. For a
         * client, **"no matches" and "this route does not exist" were therefore
         * indistinguishable**: same status code, same body.
         *
         * An unknown entity still throws and arrives as 404 with
         * `contentfly_general_unknown_entity` — that is the case the code was meant for. A known
         * entity without matches responds with 200 and an empty list, just as a query with one
         * match responds with 200 and a list with one entry.
         *
         * This is the only part of the envelope unification that is coming already now. Why the
         * rest is waiting is explained in an_project/docs/api-envelope.md.
         */
        if($data === null){
            $data = array('objects' => array(), 'totalObjects' => 0);
        }

        if($doCount){
            return $this->renderResponse(array('data' => count($data['objects'])));
        }


        if($currentPage) {
            $data = array('data' => $data['objects'], 'itemsPerPage' => $itemsPerPage, 'totalItems' => $data['totalObjects']);

            if($lastModified){
                $currentDate = new \Datetime();
                $data['lastModified'] = $currentDate->format('Y-m-d H:i:s');
            }
            return $this->renderResponse($data);
        } else {
            $data = array('data' => $data['objects'], 'totalItems' => $data['totalObjects']);

            if($lastModified){
                $currentDate = new \Datetime();
                $data['lastModified'] = $currentDate->format('Y-m-d H:i:s');
            }
            return $this->renderResponse($data);
        }
    }

    /**
     * Updates a batch of objects — all or nothing.
     *
     * DECISION ON 000-000-0009: Of the three directions left open there (transaction, error
     * collection, only improving the response), the TRANSACTION applies.
     *
     * The reason is not tidiness but what the caller knows afterwards. Previously the batch ran
     * through without a transaction: if the third of five objects failed, two remained changed,
     * three untouched, and the response was an error with no indication of how far it had got.
     * The caller had to query the state of its data back in order to know it.
     *
     * The error collection (direction 2) would have reported that, but kept the mixed state — and
     * it depends on the response arriving. If the response gets lost on the way, the caller is
     * back where it was before. The transaction holds even then: if no response arrives, either
     * everything was written or nothing, and repeating the whole batch is safe.
     *
     * THIS IS A CHANGE IN BEHAVIOUR and is noted as such in an_project/docs/breaking-changes.md.
     * A project that relied on the objects before the error remaining written now gets them
     * rolled back.
     *
     * \Throwable is caught, not \Exception: doUpdate() takes entity, id and data as typed
     * parameters: an entry without these keys raises a TypeError, and that is not an Exception.
     * If it fell through here, the transaction would remain open and the connection would clear
     * it away at the end of the request without a commit — correct in outcome, but by accident.
     */
    public function multiupdateAction(Request $request)
    {
        $objects             = ($request->request->all()['objects'] ?? null);
        $disableModifiedTime = ($request->request->all()['disableModifiedTime'] ?? null);
        $lang                = ($request->request->all()['lang'] ?? null);

        // Previously foreach ran through over null and the call ended with 200. As long as the
        // response was empty, nobody noticed; now that it lists what was written, an empty list in
        // reply to a broken request would be false information.
        if(!is_array($objects)){
            throw new ContentflyException(Messages::contentfly_general_invalid_params, 'objects');
        }

        $connection     = $this->app['orm.em']->getConnection();
        $updated        = array();

        $connection->beginTransaction();

        try{
            foreach($objects as $object){
                $api = new Api($this->app, $request);
                $updateUniversalLangProps = $api->doUpdate($object['entity'], $object['id'], $object['data'], $disableModifiedTime, null, $lang);

                if($updateUniversalLangProps){
                    foreach ($updateUniversalLangProps['i18nObjects'] as $i18nObject) {

                        $api->doUpdate($object['entity'], $i18nObject['id'], $updateUniversalLangProps['i18nProperties'], $disableModifiedTime, null, $i18nObject['lang'], true);
                    }
                }

        // One entry per object from the request, in its order. The copies written into the other
        // languages are a consequence of the same entry and not entries of their own.
                $updated[] = array('entity' => $object['entity'], 'id' => $object['id']);
            }

            $connection->commit();
        }catch(\Throwable $e){
            $connection->rollBack();
            throw $e;
        }

        $currentDate = new \DateTime();

        return $this->renderResponse(array(
            'ts'    => $currentDate->format('Y-m-d H:i:s'),
            'data'  => $updated
        ));
    }

    /**
     * @apiVersion 1.4.2
     * @api {post} /api/update update
     * @apiName Update
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     * @apiDescription API endpoint for adding a new object of an entity.
     *
     * Date fields should be transmitted in ISO 8601 format.
     *
     * @apiParam {String} entity entity to update
     * @apiParam {Integer} id Object ID to update
     * @apiParam {String=null} pass Password of the logged-in user. Must be passed if the pass property for the entity PIM\User is set under data.
     * @apiParam {Object} data Data of the object, depending on the entity
     * @apiParamExample {json} Request example:
     *     {
     *      "entity": "News",
     *      "id": 12,
     *      "data": {
     *          "title": "A changed news item",
     *          "subtitle: "Subtitle of the changed news item",
     *          "date": "2016-02-18 15:30:00"
     *      }
     * @apiError 400 object to update does not exist
     * @apiError 500 An object with the same UNIQUE-INDEX already exists
     */
    /**
     * @apiVersion 1.5.0
     * @api {post} /api/update update
     * @apiName Update
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     * @apiDescription API endpoint for adding a new object of an entity.
     *
     * Date fields should be transmitted in ISO 8601 format.
     *
     * @apiParam {String} entity entity to update
     * @apiParam {Integer} id Object ID to update
     * @apiParam {String} [lang = null] Language code for language-dependent entities (I18N)
     * @apiParam {String} [pass=null] Password of the logged-in user. Must be passed if the pass property for the entity PIM\User is set under data.
     * @apiParam {Object} data Data of the object, depending on the entity
     * @apiParamExample {json} Request example:
     *     {
     *      "entity": "News",
     *      "id": 12,
     *      "data": {
     *          "title": "A changed news item",
     *          "subtitle: "Subtitle of the changed news item",
     *          "date": "2016-02-18 15:30:00"
     *      }
     * @apiError 400 object to update does not exist
     * @apiError 500 An object with the same UNIQUE-INDEX already exists
     */
    public function updateAction(Request $request)
    {
        $entityName          = ($request->request->all()['entity'] ?? null);
        $id                  = ($request->request->all()['id'] ?? null);
        $lang                = ($request->request->all()['lang'] ?? null);
        $data                = ($request->request->all()['data'] ?? null);
        $currentUserPass     = ($request->request->all()['pass'] ?? null);
        $disableModifiedTime = ($request->request->all()['disableModifiedTime'] ?? null);

        $event = new \Areanet\PIM\Classes\Event();
        $event->setParam('entity',  $entityName);
        $event->setParam('request', $request);
        $event->setParam('id',      $id);
        $event->setParam('lang',    $lang);
        $event->setParam('user',    $this->app['auth.user']);
        $event->setParam('data',    $data);
        $event->setParam('app',     $this->app);
        $this->app['dispatcher']->dispatch($event, 'pim.entity.before.udpdate');
        $this->app['dispatcher']->dispatch($event, 'pim.entity.before.update');

        $data = $event->getParam('data');

        $api = new Api($this->app, $request);
        $updateUniversalLangProps = $api->doUpdate($entityName, $id, $data, $disableModifiedTime, $currentUserPass, $lang);

        if($updateUniversalLangProps){
            foreach ($updateUniversalLangProps['i18nObjects'] as $i18nObject) {
                $api->doUpdate($entityName, $i18nObject['id'], $updateUniversalLangProps['i18nProperties'], $disableModifiedTime, $currentUserPass, $i18nObject['lang'], true);
            }
        }

        $event = new \Areanet\PIM\Classes\Event();
        $event->setParam('entity',  $entityName);
        $event->setParam('request', $request);
        $event->setParam('id',      $id);
        $event->setParam('lang',    $lang);
        $event->setParam('user',    $this->app['auth.user']);
        $event->setParam('data',    $data);
        $event->setParam('app',     $this->app);
        $this->app['dispatcher']->dispatch($event, 'pim.entity.after.udpdate');
        $this->app['dispatcher']->dispatch($event, 'pim.entity.after.update');

        $currentDate = new \Datetime();

        return $this->renderResponse(array('ts' => $currentDate->format('Y-m-d H:i:s'), 'id' => $id));

    }

    protected function renderResponse(Array $data, $status = 200){
        $data['version']    = APP_VERSION;
        $data['hash']       = $this->app['schema']['_hash'];
        return new JsonResponse($data, $status);
    }

    /**
     * @apiVersion 1.4.2
     * @api {post} /api/replace replace
     * @apiName Replace
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiDescription API endpoint for retrieving objects of an entity. If the object exists, an insert is performed, otherwise an update.
     *
     * Date fields should be transmitted in ISO 8601 format.
     *
     * @apiParam {String} entity entity to update or insert
     * @apiParam {Integer/String} [id=null] Object ID (if present, the object is updated, otherwise newly created)
     * @apiParam {Object} data Data of the object, depending on the entity
     * @apiParamExample {json} Request example:
     *     {
     *      "entity": "News",
     *      "id": 12,
     *      "data": {
     *          "title": "A changed news item",
     *          "subtitle: "Subtitle of the changed news item",
     *          "date": "2016-02-18 15:30:00"
     *      }
     * @apiError 500 An object with the same UNIQUE-INDEX already exists
     */
    /**
     * @apiVersion 1.5.0
     * @api {post} /api/replace replace
     * @apiName Replace
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiDescription API endpoint for retrieving objects of an entity. If the object exists, an insert is performed, otherwise an update.
     *
     * Date fields should be transmitted in ISO 8601 format.
     *
     * @apiParam {String} entity entity to update or insert
     * @apiParam {Integer/String} [id=null] Object ID (if present, the object is updated, otherwise newly created)
     * @apiParam {String} [lang = null] Language code for language-dependent entities (I18N)
     * @apiParam {Object} data Data of the object, depending on the entity
     * @apiParamExample {json} Request example:
     *     {
     *      "entity": "News",
     *      "id": 12,
     *      "data": {
     *          "title": "A changed news item",
     *          "subtitle: "Subtitle of the changed news item",
     *          "date": "2016-02-18 15:30:00"
     *      }
     * @apiError 500 An object with the same UNIQUE-INDEX already exists
     */
    public function replaceAction(Request $request)
    {
        $entityName          = ($request->request->all()['entity'] ?? null);
        $id                  = ($request->request->all()['id'] ?? null);
        $lang                = ($request->request->all()['lang'] ?? null);
        $data                = ($request->request->all()['data'] ?? null);


        $helper             = new Helper();
        $entityFullName     = $helper->getFullEntityName($entityName);

        $schema = $this->app['schema'];
        $object = null;

        if($schema[$entityName]['settings']['i18n']) {
            $object = $this->em->getRepository($entityFullName)->find(array('id' => $id, 'lang' => $lang));
        }else{
            $object = $this->em->getRepository($entityFullName)->find($id);
        }

        /**
         * Two internal sub-requests — and what has to be checked about them during the kernel
         * switch (009-001-0004).
         *
         * `replace` does not decide by itself whether to create or to change, but sends the request
         * through the application once more — to `/api/insert` if the object does not exist,
         * otherwise to `/api/update`. That is not a call of the method but a complete request
         * through the kernel: **before and after hooks run a second time**, and the event name that
         * `BaseControllerProvider` builds from it is then `pim.controller.before.api.insertaction`
         * instead of `…replaceaction`.
         *
         * The three lines before it are the reason this works at all: the id from the request moves
         * to `data.id`, and `$subRequest` takes over `request` and `query` of the original by
         * reference — otherwise the body would not come along, because `Request::create()` does not
         * parse it again from `getContent()`.
         *
         * `handle()` comes from Symfony's `HttpKernelInterface`, not from Silex. The call therefore
         * stays literally as it is. **What has to be checked is something else:** whether
         * `SUB_REQUEST` passes through the same listeners with the new kernel. Symfony
         * distinguishes main and sub requests in `kernel.request`; a listener that runs along today
         * can be skipped there — and that would be a change in behaviour that only
         * `UpdateReplaceApiTest` makes visible.
         */
        if(!$object){
            $subRequest = Request::create('/api/insert', 'POST', $request->attributes->all(), $request->cookies->all(), $request->files->all(), $request->server->all(), $request->getContent());

            $allData = $request->request->all();
            $allData["data"]["id"] = $id;
            $request->request->replace($allData);
            $request->attributes->set("data", $data);

            $subRequest->request = $request->request;
            $subRequest->query = $request->query;
            return $this->app->handle($subRequest, HttpKernelInterface::SUB_REQUEST);

        }

        $subRequest = Request::create('/api/update', 'POST', $request->attributes->all(), $request->cookies->all(), $request->files->all(), $request->server->all(), $request->getContent());

        $allData = $request->request->all();
        $allData["data"]["id"] = $id;
        $request->request->replace($allData);
        $request->attributes->set("data", $data);

        $subRequest->request = $request->request;
        $subRequest->query = $request->query;
        return $this->app->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
    }

    /**
     * @apiVersion 1.4.2
     * @api {get} /api/schema schema
     * @apiName Schema
     * @apiGroup Settings
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiDescription Returns the schema of all entities.
     *
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "devmode": false,
     *       "version": "1.4.0"
     *       "data:" {
     *         ...
     *       }
     *     }
     */
    public function schemaAction()
    {
        $api = new Api($this->app);
        $extendedSchema = $api->getExtendedSchema();

        return $this->renderResponse($extendedSchema);

    }

    /**
     * @apiVersion 1.4.2
     * @api {post} /api/single single
     * @apiName Single
     * @apiDescription API endpoint for retrieving a single object of an entity.
     *
     * The data is returned in JSON format based on the Doctrine ORM. Joins (1:n) and multijoins
     * (n:m) are converted automatically and returned as sub-objects.
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} entity Entity to read
     * @apiParam {String/Integer} [id = null] ID of the object
     * @apiParam {Object} [where = null] Condition; multiple fields are combined with AND: <code>{'title': 'test', 'desc': 'foo',...}</code>
     * @apiParamExample {json} Request example by ID:
     *     {
     *      "entity": "News",
     *      "id": 1
     *     }
     * @apiParamExample {json} Request example by WHERE:
     *     {
     *      "entity": "Customers",
     *      "where": {"customerNumber": 200200}
     *     }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "message": "singleAction",
     *       "data:" {
     *          "id": 1,
     *          "isHidden": false,
     *          "isDeleted": false,
     *          "title": "A news item"
     *       }
     *   }
     * @apiError 404 Object not found
     */
    /**
     * @apiVersion 1.5.0
     * @api {post} /api/single single
     * @apiName Single
     * @apiDescription API endpoint for retrieving a single object of an entity.
     *
     * The data is returned in JSON format based on the Doctrine ORM. Joins (1:n) and multijoins
     * (n:m) are converted automatically and returned as sub-objects.
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} entity Entity to read
     * @apiParam {String/Integer} [id = null] ID of the object
     * @apiParam {String} [lang = null] Language code for language-dependent entities (I18N)
     * @apiParam {String} [compareToLang = null] For a translation, loads linked objects from 'compareToLang' and outputs an error message if translations are missing (I18N)
     * @apiParam {String} [compareToMainLang = null] For a translation, loads linked objects from the main language and outputs an error message if translations are missing (I18N)
     * @apiParam {Object} [where = null] Condition; multiple fields are combined with AND: <code>{'title': 'test', 'desc': 'foo',...}</code>
     * @apiParamExample {json} Request example by ID:
     *     {
     *      "entity": "News",
     *      "id": 1
     *     }
     * @apiParamExample {json} Request example by WHERE:
     *     {
     *      "entity": "Customers",
     *      "where": {"customerNumber": 200200}
     *     }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "message": "singleAction",
     *       "data:" {
     *          "id": 1,
     *          "isHidden": false,
     *          "isDeleted": false,
     *          "title": "A news item"
     *       }
     *   }
     * @apiError 404 Object not found
     */
    public function singleAction(Request $request)
    {

        $data               = array();

        $entityName         = ($request->request->all()['entity'] ?? null);
        $id                 = ($request->request->all()['id'] ?? null);
        $lang               = ($request->request->all()['lang'] ?? null);
        $compareToLang      = ($request->request->all()['compareToLang'] ?? null);
        $loadJoinedLang     = ($request->request->all()['loadJoinedLang'] ?? null);
        $where              = ($request->request->all()['where'] ?? null);

        $api  = new Api($this->app);
        $data = $api->getSingle($entityName, $id, $where, $lang, false, $compareToLang, $loadJoinedLang);

        // Since 000-000-0006, getSingle() reports "not found" with null instead of a ready-made
        // JsonResponse. The decision which status code that results in belongs here and not in the
        // Api class. Previously this endpoint responded with 200 and
        // `data: {"headers": {}}` — the serialised response it passed on.
        if($data === null){
            throw new ContentflyException(Messages::contentfly_general_not_found, $entityName, Messages::contentfly_status_not_found);
        }
        $currentDate    = new \Datetime();

        return $this->renderResponse(array('ts' => $currentDate->format('Y-m-d H:i:s'), 'data' => $data));

    }

    /**
     * @apiVersion 1.4.2
     * @api {post} /api/tree tree
     * @apiName Tree view
     * @apiDescription API endpoint for retrieving a tree structure.
     *
     * The entity must be of type Areanet\PIM\Entity\BaseTree
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} entity Entity to read
     * @apiParam {Array} [properties=null] Returns only the specified properties/fields, otherwise all properties are loaded (performance!)<code>['field1', 'field2', ...]</code>
     * @apiParamExample {json} Request example:
     *     {
     *      "entity": "Category",
     *      "properties": ["title"]
     *     }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "message": "treeAction",
     *       "data:" [
     *          {
     *              "id": 1,
     *              "isHidden": false,
     *              "isDeleted": false,
     *              "title": "A category",
     *              "treeChilds" : [
     *                  {
     *                      ....
     *                  }
     *              ]
     *          },
     *          {...},
     *          ...
     *      ]
     *   }
     */
    /**
     * @apiVersion 1.5.0
     * @api {post} /api/tree tree
     * @apiName Tree view
     * @apiDescription API endpoint for retrieving a tree structure.
     *
     * The entity must be of type Areanet\PIM\Entity\BaseTree
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} entity Entity to read
     * @apiParam {Array} [properties=null] Returns only the specified properties/fields, otherwise all properties are loaded (performance!)<code>['field1', 'field2', ...]</code>
     * @apiParam {String} [lang=null] Language variant
     * @apiParamExample {json} Request example:
     *     {
     *      "entity": "Category",
     *      "properties": ["title"]
     *     }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "message": "treeAction",
     *       "data:" [
     *          {
     *              "id": 1,
     *              "isHidden": false,
     *              "isDeleted": false,
     *              "title": "A category",
     *              "treeChilds" : [
     *                  {
     *                      ....
     *                  }
     *              ]
     *          },
     *          {...},
     *          ...
     *      ]
     *   }
     */
    public function treeAction(Request $request)
    {
        $entityName   = ($request->request->all()['entity'] ?? null);
        $lang         = ($request->request->all()['lang'] ?? null);
        $properties   = ($request->request->all()['properties'] ?? null);

        $api            = new Api($this->app);
        $tree           = $api->getTree($entityName, null, $properties, $lang);
        $currentDate    = new \Datetime();

        return $this->renderResponse(array('ts' => $currentDate->format('Y-m-d H:i:s'),'data' => $tree));
    }

    /**
     * @apiVersion 1.5.2
     * @api {post} /api/tree2 tree2
     * @apiName Tree view optimized
     * @apiDescription API endpoint for retrieving an optimized/performant tree structure.
     *
     * The entity must be of type Areanet\PIM\Entity\BaseTree
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} entity Entity to read
     * @apiParam {String} [lang=null] Language variant
     * @apiParamExample {json} Request example:
     *     {
     *      "entity": "Category"
     *     }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "message": "treeAction",
     *       "data:" [
     *          {
     *              "id": 1,
     *              "isHidden": false,
     *              "isDeleted": false,
     *              "title": "A category",
     *              "treeChilds" : [
     *                  {
     *                      ....
     *                  }
     *              ]
     *          },
     *          {...},
     *          ...
     *      ]
     *   }
     */
    public function tree2Action(Request $request)
    {
        $entityName   = ($request->request->all()['entity'] ?? null);
        $lang         = ($request->request->all()['lang'] ?? null);

        $api            = new Api($this->app);
        $tree           = $api->getTree2($entityName,  $lang);
        $currentDate    = new \Datetime();

        return $this->renderResponse(array('ts' => $currentDate->format('Y-m-d H:i:s'),'data' => $tree));
    }

    /**
     * @apiVersion 1.5.0
     * @api {post} /api/translations translations
     * @apiName Translations
     * @apiDescription Returns the number of pending translations for an entity.
     *
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParam {String} entity Entity to read
     * @apiParam {String} lang Language code (e.g. de, en,..) for which untranslated records are to be analysed.
     * @apiParamExample {json} Request example:
     *     {
     *      "entity": "Category",
     *      "lang": "en"
     *     }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *     {
     *       "message": "translationsAction",
     *       "data": [
     *         {
     *           "lang": "de",
     *           "records": "4"
     *         }, ...
     *       ],
     *   }
     */
    public function translationsAction(Request $request)
    {
        $entityName = ($request->request->all()['entity'] ?? null);
        $lang       = ($request->request->all()['lang'] ?? null);

        $api  = new Api($this->app);
        $lang = $api->getTranslations($entityName, $lang);

        return $this->renderResponse(array('data' => $lang));
    }

    /**
     * @apiVersion 1.4.2
     * @api {post} /api/query query
     * @apiDescription Extended API endpoint through which almost arbitrary queries can be run against the database/entities. The query syntax is based on Doctrine's DBAL QueryBuilder (http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/query-builder.html). The JSON request (see examples below) is converted in the Contentfly CMS into an equivalent DBAL query via the QueryBuilder.
     *
     * The data is returned in JSON format. Because of the DBAL query, the data is returned directly
     * at database level and not as Doctrine entities.
     * @apiName Query
     * @apiGroup Objects
     * @apiHeader {String} APPMS-TOKEN Access-Token
     * @apiHeader {String} Content-Type=application/json
     *
     * @apiParamExample {json} Simple query:
     *     {
     *      "select": "*",
     *      "from": "Product"
     *     }
     * @apiParamExample {json} Simple query with where
     *     {
     *      "select": "*",
     *      "from": "Product",
     *      "where": {"active": true},
     *     }
     * @apiParamExample {json} Query with group, Count(), limit and offset
     *     {
     *      "select": ['title', 'field2', 'COUNT(id) AS users'],
     *      "from": "Product",
     *      "where": {"active": true},
     *      "groupBy": "category",
     *      "having": {"field": "value"},
     *      "orderBy": {"field": "ASC"},
     *      "addOrderBy": {"field": "DESC"},
     *      "setFirstResult": 10, //Offfset,
     *      "setMaxResults": 20, //Limit
     *     }
     * @apiSuccessExample Success-Response:
     *     HTTP/1.1 200 OK
     *      [
     *          {
     *              "name" : "Product1",
     *              "active": true
     *          },
     *          {
     *              ..
     *          }
     *      ]
     */
    public function queryAction(Request $request){
        $params = $request->request->all();

        $api            = new Api($this->app, $request);
        $data           = $api->getQuery($params);
        $currentDate    = new \Datetime();

        return $this->renderResponse(array('ts' => $currentDate->format('Y-m-d H:i:s'), 'params' => $params, 'data' => $data));
    }



}