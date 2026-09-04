<?php
namespace Areanet\PIM\Classes\Controller;

use Doctrine\ORM\EntityManager;
use Silex\Application;
use Twig\Environment;

abstract class BaseController
{
    /** @var Application $app */
    protected $app;

    /** @var EntityManager $em */
    protected $em;

    /** @var \Twig_Environment $twig */
    protected $twig;

    public function __construct($app)
    {
        $this->app = $app;
        if($this->app['orm.em']) $this->setEM($this->app['orm.em']);
        $this->setTwig($this->app['twig']);
        
    }

    protected function setEM(EntityManager $em){
        $this->em = $em;
    }

    protected function setTwig(Environment $twig){
        $this->twig = $twig;
    }
    
}
