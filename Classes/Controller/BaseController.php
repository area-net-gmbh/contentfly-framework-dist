<?php
namespace Areanet\PIM\Classes\Controller;

use Doctrine\ORM\EntityManager;
use Areanet\PIM\Classes\Envelope;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;
use Symfony\Component\HttpFoundation\JsonResponse;

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

    /**
     * The one answer of the API: `data`, `errors`, `meta` (`011-001-0004`).
     *
     * IT SITS HERE, NOT IN ApiController, BECAUSE THERE ARE FOUR CONTROLLERS. `/api/*` was moved
     * first (`011-001-0002`) and had the method to itself; `/auth/*`, `/file/*` and `/system/do`
     * follow now, and every one of them built its own body before — `message` and `token` here,
     * `message` and `data` there, `method`, `datetime` and `message` in the third. As long as the
     * method lived in one of the four, the other three had a reason to keep improvising.
     *
     * What goes into `data` and what into `meta` is decided per endpoint in
     * `an_project/docs/api-envelope.md`. The hull itself comes from `Classes\Envelope`, which the
     * error handler in `bootstrap-web.php` uses as well.
     *
     * @param mixed $data the payload, as that endpoint's row in api-envelope.md describes it
     * @param array<string,mixed> $meta what this endpoint adds to the standard meta
     */
    protected function renderResponse(mixed $data = null, int $status = 200, array $meta = array()): JsonResponse
    {
        return new JsonResponse(
            Envelope::success($data, Envelope::schemaHash($this->app), $meta),
            $status
        );
    }

    /**
     * A rejection this controller decides itself — in the same hull (`011-001-0004`).
     *
     * NOT EVERY REJECTION IS AN EXCEPTION. A wrong password, a spent refresh token, an address over
     * the throttle limit: `AuthController` answers those itself and never reaches the error handler,
     * so before `011-001-0004` they were the one group left with a body of their own
     * (`{"message": …}`). They are foreseeable states, so each of them names a `Messages` key a
     * client can branch on — that is what `code` is for.
     *
     * @param array<string,string> $headers extra response headers, e.g. `Retry-After`
     */
    protected function renderError(string $code, string $detail, int $status, array $headers = array()): JsonResponse
    {
        return new JsonResponse(
            Envelope::failure(
                array(Envelope::fault($code, $detail, static::class)),
                Envelope::schemaHash($this->app)
            ),
            $status,
            $headers
        );
    }
}
