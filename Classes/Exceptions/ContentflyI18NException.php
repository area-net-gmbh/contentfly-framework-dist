<?php
/**
 * Created by PhpStorm.
 * User: ms
 * Date: 06.08.18
 * Time: 15:52
 */

namespace Areanet\PIM\Classes\Exceptions;

use Areanet\PIM\Classes\Messages;


class ContentflyI18NException extends \Exception
{
    protected $entity   = null;
    protected $lang     = null;

    /**
     * The code becomes the HTTP status of the error response, as for ContentflyException.
     *
     * It was a fixed 550 until 000-000-0095 — no HTTP status, and as a 5xx it read as a server
     * error that a client or proxy may retry. The default is now 403, because most of these
     * exceptions refuse a language the group may not write; a caller that means something else
     * passes its own status.
     */
    public function __construct($message, $value, $lang, $code = Messages::contentfly_status_access_denied) {
        parent::__construct($message, $code);

        $this->entity = $value;
        $this->lang = $lang;
    }

    /**
     * @return null
     */
    public function getEntity()
    {
        return $this->entity;
    }

    /**
     * @return null
     */
    public function getLang()
    {
        return $this->lang;
    }


}