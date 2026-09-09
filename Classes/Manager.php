<?php
namespace Areanet\PIM\Classes;

use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

class Manager
{
    /** @var Application */
    protected $app;

    /**
     * Manager constructor.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        
        $this->app = $app;
    }
}