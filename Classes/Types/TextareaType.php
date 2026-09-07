<?php
namespace Areanet\PIM\Classes\Types;
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

    public function getAnnotationFile()
    {
        return null;
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

        if(empty(Adapter::getConfig()->SECURITY_CIPHER_KEY)){
            throw new \Exception('Für die Verschlüsselung muss ein Wert für SECURITY_CIPHER_KEY gesetzt sein.');
        }

        $encryptedValue = $object->$getter();

        if(empty($encryptedValue)){
            return '';
        }

        $encryptedValue = base64_decode($encryptedValue);
        $iv             = substr($encryptedValue, 0, openssl_cipher_iv_length(Adapter::getConfig()->SECURITY_CIPHER_METHOD));
        $encryptedValue = substr($encryptedValue, openssl_cipher_iv_length(Adapter::getConfig()->SECURITY_CIPHER_METHOD));

        return openssl_decrypt($encryptedValue, Adapter::getConfig()->SECURITY_CIPHER_METHOD, Adapter::getConfig()->SECURITY_CIPHER_KEY, 0, $iv);

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

        if(empty(Adapter::getConfig()->SECURITY_CIPHER_KEY)){
            throw new \Exception('Für die Verschlüsselung muss ein Wert für SECURITY_CIPHER_KEY gesetzt sein.');
        }

        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(Adapter::getConfig()->SECURITY_CIPHER_METHOD));

        $encryptedValue = openssl_encrypt($value, Adapter::getConfig()->SECURITY_CIPHER_METHOD, Adapter::getConfig()->SECURITY_CIPHER_KEY, 0, $iv);
        $encryptedValue = base64_encode($iv.$encryptedValue);

        $object->$setter($encryptedValue);

    }
}
