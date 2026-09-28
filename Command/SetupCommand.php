<?php
namespace Areanet\PIM\Command;

use Areanet\PIM\Entity\ThumbnailSetting;
use Areanet\PIM\Entity\User;
use Areanet\PIM\Classes\Kernel\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SetupCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('appcms:setup')
            ->setDescription('Setup routine for APP-CMS')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app   = $this->application();
        $em    = $app['orm.em'];

        /*
         * A SETUP RUN IS NOT A PASSWORD RESET (015-000-0002).
         *
         * This command used to announce "Log in with user=admin and password=admin" — and it
         * was telling the truth, because `install()` set exactly that on every run, on an
         * existing account too. Running it a second time on a live instance therefore reopened
         * the admin account to anyone who knew the default.
         *
         * `install()` now only writes an account it creates, and the password for a new one is
         * generated here and shown once. An existing account keeps what it has.
         */
        $adminCreated      = $app['helper']->install($em);
        $generatedPassword = $app['helper']->applyAdminPassword($em, $adminCreated);

        $output->writeln("<info>APP-CMS setup completed successfully.</info>");

        if ($generatedPassword !== null) {
            $output->writeln('<info>An admin account was created. Login: admin / '.$generatedPassword.'</info>');
            $output->writeln('<comment>This password was generated now, is shown this one time and is stored nowhere else. Note it down.</comment>');
        } else {
            $output->writeln('<info>The existing admin account is unchanged.</info>');
        }

        // The only command without a return value — under Console 4, execute() was untyped and
        // a missing return silently yielded null, which Symfony read as 0. Console 7 requires
        // the int (009-002-0005).
        return 0;
    }
}