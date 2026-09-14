<?php
namespace Areanet\PIM\Classes\Types;

use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Messages;
use Areanet\PIM\Classes\Type;
use Areanet\PIM\Entity\Base;


class SelectType extends Type
{
    public function getPriority()
    {
        return 10;
    }
    
    public function getAlias()
    {
        return 'select';
    }


    public function doMatch($propertyAnnotations){
        if(!isset($propertyAnnotations['Areanet\\PIM\\Classes\\Annotations\\Select'])) {
            return false;
        }

        return true;
    }

    public function processSchema($key, $defaultValue, $propertyAnnotations, $entityName){
        $schema                 = parent::processSchema($key, $defaultValue, $propertyAnnotations, $entityName);
        $propertyAnnotations    = $propertyAnnotations['Areanet\\PIM\\Classes\\Annotations\\Select'];

        $options = explode(',', $propertyAnnotations->options);

        $optionsData = array();
        $count = 0;
        foreach($options as $option){
            $optionSplit = explode('=', $option);
            if(count($optionSplit) == 1){
                $optionsData[] = array(
                    "id" => trim($optionSplit[0]),
                    "name" => trim($optionSplit[0])
                );
                $count++;
            }else{
                $optionsData[] = array(
                    "id" => trim($optionSplit[0]),
                    "name" => trim($optionSplit[1])
                );
            }
        }

        $schema['options'] = $optionsData;

        return $schema;
    }

    public function toDatabase(Api $api, Base $object, $property, $value, $entityName, $schema, $user, $data = null, $lang = null): void
    {
        $setter = 'set'.ucfirst($property);
        $config = $schema[ucfirst($entityName)]['properties'][$property];

        $this->validateValue($entityName, $property, $value, $config);

        if($config['dbtype'] == 'integer'){
            $object->$setter(intval($value));
        }else{
            $object->$setter($value);
        }

    }

    /**
     * Checks the write value against the options of the annotation (000-000-0017).
     *
     * UNTIL THEN @PIM\Select VALIDATED NOTHING. The options were in the schema, but nobody
     * compared a write value against them: `state: "gibtsnicht"` was accepted and ended up
     * unchanged in the column. The only consumer of the list was the deleted user interface —
     * what remained was a guarantee in the schema that guaranteed nothing.
     *
     * THIS IS A BEHAVIOUR CHANGE for existing projects and is recorded as such in
     * an_project/docs/breaking-changes.md: a project whose data today contains values
     * outside the list gets an error on the next write.
     *
     * NULL AND EMPTY PASS THROUGH. Whether a field may be empty is decided by `nullable` on the
     * column type, not by the option list — otherwise this check would incidentally have made
     * every select field a required field.
     */
    private function validateValue($entityName, $property, $value, array $config): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (empty($config['options']) || !is_array($config['options'])) {
            return;
        }

        $allowed = array_column($config['options'], 'id');

        if (in_array((string) $value, array_map('strval', $allowed), true)) {
            return;
        }

        throw new ContentflyException(
            Messages::contentfly_general_invalid_params,
            sprintf(
                '%s::%s — "%s" is not one of the allowed options (%s)',
                ucfirst($entityName),
                $property,
                is_scalar($value) ? (string) $value : gettype($value),
                implode(', ', $allowed)
            )
        );
    }
}
