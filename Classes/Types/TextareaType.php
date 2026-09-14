<?php
namespace Areanet\PIM\Classes\Types;
use Areanet\PIM\Classes\Security\FieldEncryption;
use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Type;
use Areanet\PIM\Controller\ApiController;
use Areanet\PIM\Entity\Base;


class TextareaType extends Type
{
    public function getPriority()
    {
        return 10;
    }
    
    public function getAlias()
    {
        return 'textarea';
    }


    public function doMatch($propertyAnnotations){
        if(!isset($propertyAnnotations['Doctrine\\ORM\\Mapping\\Column'])) {
            return false;
        }

        $annotation = $propertyAnnotations['Doctrine\\ORM\\Mapping\\Column'];

        return ($annotation->type == 'text');
    }

    public function fromDatabase(Base $object, $entityName, $property, $flatten = false, $level = 0, $propertiesToLoad = array())
    {
        $getter = 'get'.ucfirst($property);

        $config = $this->app['schema'][ucfirst($entityName)]['properties'][$property];
        if(empty($config['encoded'])){
            return $object->$getter();
        }

        $encryptedValue = $object->$getter();

        if(empty($encryptedValue)){
            return '';
        }

        // Seit 010-004-0001 an einer Stelle: Classes/Security/FieldEncryption.
        // Die Ausnahme bei fehlendem SECURITY_CIPHER_KEY wirft jetzt sie.
        return (new FieldEncryption())->decrypt($encryptedValue);
    }

    public function toDatabase(Api $api, Base $object, $property, $value, $entityName, $schema, $user, $data = null, $lang = null): void
    {
        $setter = 'set'.ucfirst($property);

        if(empty($value)){
            $object->$setter('');
            return;
        }

        $config = $this->app['schema'][ucfirst($entityName)]['properties'][$property];
        if(empty($config['encoded'])){
            $object->$setter($value);
            return;
        }

        $object->$setter((new FieldEncryption())->encrypt($value));

    }
}
