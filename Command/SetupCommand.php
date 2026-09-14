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
            ->setDescription('Setup-Routine für APP-CMS')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app   = $this->application();
        $em    = $app['orm.em'];

        $app['helper']->install($em);

        $output->writeln("<info>APP-CMS Setup wurde erfolgreich durchgeführt. Login in das Backend mit Benutzer=admin und Passwort=admin!</info>");

        // Der einzige Command ohne Rueckgabe — unter Console 4 war execute() untypisiert und
        // ein fehlendes return ergab still null, was Symfony als 0 las. Console 7 verlangt den
        // int (009-002-0005).
        return 0;
    }
}