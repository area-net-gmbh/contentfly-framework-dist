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

        $app['helper']->install($em);

        $output->writeln("<info>APP-CMS setup completed successfully. Log in with user=admin and password=admin!</info>");

        // The only command without a return value — under Console 4, execute() was untyped and
        // a missing return silently yielded null, which Symfony read as 0. Console 7 requires
        // the int (009-002-0005).
        return 0;
    }
}