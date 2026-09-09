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

        $this->wertPruefen($entityName, $property, $value, $config);

        if($config['dbtype'] == 'integer'){
            $object->$setter(intval($value));
        }else{
            $object->$setter($value);
        }

    }

    /**
     * Prueft den Schreibwert gegen die Optionen der Annotation (000-000-0017).
     *
     * BIS DAHIN VALIDIERTE @PIM\Select NICHTS. Die Optionen standen im Schema, aber niemand
     * verglich einen Schreibwert damit: `state: "gibtsnicht"` wurde angenommen und landete
     * unveraendert in der Spalte. Der einzige Konsument der Liste war die geloeschte
     * Oberflaeche — was blieb, war eine Zusicherung im Schema, die nichts zusicherte.
     *
     * DAS IST EINE VERHALTENSAENDERUNG fuer Bestandsprojekte und als solche in
     * an_project/docs/breaking-changes.md vermerkt: Ein Projekt, dessen Daten heute Werte
     * ausserhalb der Liste enthalten, bekommt beim naechsten Schreiben einen Fehler.
     *
     * NULL UND LEER GEHEN DURCH. Ob ein Feld leer sein darf, entscheidet `nullable` am
     * Spaltentyp, nicht die Optionsliste — sonst haette diese Pruefung nebenbei jedes
     * Select-Feld zum Pflichtfeld gemacht.
     */
    private function wertPruefen($entityName, $property, $value, array $config): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (empty($config['options']) || !is_array($config['options'])) {
            return;
        }

        $erlaubt = array_column($config['options'], 'id');

        if (in_array((string) $value, array_map('strval', $erlaubt), true)) {
            return;
        }

        throw new ContentflyException(
            Messages::contentfly_general_invalid_params,
            sprintf(
                '%s::%s — "%s" ist keine der erlaubten Optionen (%s)',
                ucfirst($entityName),
                $property,
                is_scalar($value) ? (string) $value : gettype($value),
                implode(', ', $erlaubt)
            )
        );
    }
}
