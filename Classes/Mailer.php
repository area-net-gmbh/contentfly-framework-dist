<?php
namespace Areanet\PIM\Classes;
use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Entity\User;
use PHPMailer\PHPMailer\PHPMailer;
use Areanet\PIM\Classes\Kernel\ApplicationInterface as Application;

/**
 * Class Mailer
 * @package Areanet\PIM\Classes
 */
class Mailer{
    /** @var Application $app */
    protected $app;

    /** @var PHPMailer $phpmail */
    public $mail;

    /**
     * Manager constructor.
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {

        $this->app = $app;
        $this->mail = new PHPMailer(Adapter::getConfig()->MAILER_EXCEPTIONS);
        $this->mail->isSMTP();
        $this->mail->Host       = Adapter::getConfig()->MAILER_SMTP_HOST;
        $this->mail->SMTPAuth   = true;
        $this->mail->Username   = Adapter::getConfig()->MAILER_SMTP_USERNAME;
        $this->mail->Password   = Adapter::getConfig()->MAILER_SMTP_PASSWORD;
        $this->mail->SMTPSecure = Adapter::getConfig()->MAILER_SMTP_SECURE;
        $this->mail->Port       = Adapter::getConfig()->MAILER_SMTP_PORT;

        if(Adapter::getConfig()->MAILER_FROM){
            $this->mail->setFrom(Adapter::getConfig()->MAILER_FROM, Adapter::getConfig()->MAILER_FROM_NAME ? Adapter::getConfig()->MAILER_FROM_NAME : Adapter::getConfig()->MAILER_FROM);
        }
    }
}
