<?php
namespace Areanet\PIM\Classes\Controller;

use Doctrine\ORM\EntityManager;
use Silex\Application;

abstract class BaseController
{
    /** @var Application $app */
    protected $app;

    /** @var EntityManager $em */
    protected $em;

    public function __construct($app)
    {
        $this->app = $app;
        // Vor der Installation registriert bootstrap.php weder DBAL noch ORM
        // (`if($app['is_installed'])`). Der Container wirft dann beim Zugriff, statt null
        // zu liefern - deshalb erst fragen, dann holen.
        if (isset($this->app['orm.em']) && $this->app['orm.em']) {
            $this->setEM($this->app['orm.em']);
        }
    }

    protected function setEM(EntityManager $em){
        $this->em = $em;
    }

    
}
