<?php
namespace Areanet\PIM\Classes\Types;
use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\I18nPermission;
use Areanet\PIM\Classes\Language;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Classes\Type;
use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Entity\Base;
use Areanet\PIM\Entity\Permission;
use Doctrine\Common\Collections\ArrayCollection;


class I18nPermissionsType extends Type
{
    public function getPriority()
    {
        return 10;
    }

    public function getAlias()
    {
        return 'i18npermissions';
    }


    public function doMatch($propertyAnnotations)
    {
        if (!isset($propertyAnnotations['Areanet\\PIM\\Classes\\Annotations\\I18nPermissions'])) {
            return false;
        }

        return true;
    }

    public function processSchema($key, $defaultValue, $propertyAnnotations, $entityName)
    {
        $schema = parent::processSchema($key, $defaultValue, $propertyAnnotations, $entityName);

        $schema['dbtype'] = "string";

        return $schema;
    }

    public function fromDatabase(Base $object, $entityName, $property, $flatten = false, $level = 0, $propertiesToLoad = array())
    {
        $getter = 'get'.ucfirst($property);

        if(!$object->$getter()){
            return null;
        }

        return is_string($object->$getter()) ? json_decode($object->$getter(), true) : $object->$getter();
    }

    public function toDatabase(Api $api, Base $object, $property, $value, $entityName, $schema, $user, $data = null, $lang = null): void
    {

        if($value){
            $value = $this->assertShape($value, $entityName, $property);
            $value = !is_string($value) ? json_encode($value) : $value;

        }

        $setter = 'set'.ucfirst($property);
        $object->$setter($value);
    }

    /**
     * THE MAP IS CHECKED BEFORE IT IS STORED (015-000-0013).
     *
     * `languages` was written through unchecked: whatever the request sent became the column,
     * and `Group::langIsWritable()` then read it. A key that is no language, or a value that is
     * no permission, was stored and silently read as "no restriction" — the same class of
     * mistake as `015-000-0011`, one layer earlier.
     *
     * Checked is only the SHAPE, not who may write it: that is `RightsManagement`'s question,
     * and it is answered before this type ever runs. What is refused here is a value that no
     * reader could make sense of, whoever sent it.
     *
     * Keys are normalised with `Language::normalise()`, so the map is stored in the spelling the
     * lookup uses. Without configured languages every key passes — see `Language::isConfigured()`
     * for why.
     *
     * @return array<string, string>|string the map to store
     * @throws ContentflyException 400 for a key that is no language or a value that is no permission
     */
    private function assertShape(mixed $value, string $entityName, string $property): mixed
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        if (!is_array($decoded)) {
            throw new ContentflyException(
                Messages::contentfly_general_invalid_params,
                $entityName.'::'.$property,
                Messages::contentfly_status_bad_request
            );
        }

        $permissions = array(I18nPermission::IS_READABLE, I18nPermission::IS_TRANSLATABALE);
        $checked     = array();

        foreach ($decoded as $language => $permission) {
            $language = Language::normalise($language);

            if ($language === null || !Language::isConfigured($language)) {
                throw new ContentflyException(
                    Messages::contentfly_general_invalid_params,
                    $entityName.'::'.$property.'::'.(is_scalar($language) ? (string) $language : 'language'),
                    Messages::contentfly_status_bad_request
                );
            }

            if (!is_string($permission) || !in_array($permission, $permissions, true)) {
                throw new ContentflyException(
                    Messages::contentfly_general_invalid_params,
                    $entityName.'::'.$property.'::'.$language,
                    Messages::contentfly_status_bad_request
                );
            }

            $checked[$language] = $permission;
        }

        return $checked;
    }


}
