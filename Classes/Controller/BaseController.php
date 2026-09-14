<?php
namespace Areanet\PIM\Classes\Controller;

use Doctrine\ORM\EntityManager;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

abstract class BaseController
{
    /** @var Application $app */
    protected $app;

    /** @var EntityManager $em */
    protected $em;

    public function __construct($app)
    {
        $this->app = $app;
        // Before installation, bootstrap.php registers neither DBAL nor ORM
        // (`if($app['is_installed'])`). The container then throws on access instead of
        // returning null - hence ask first, then fetch.
        if (isset($this->app['orm.em']) && $this->app['orm.em']) {
            $this->setEM($this->app['orm.em']);
        }
    }

    protected function setEM(EntityManager $em){
        $this->em = $em;
    }

    
}
