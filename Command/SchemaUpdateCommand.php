<?php
namespace Areanet\PIM\Command;

use Areanet\PIM\Classes\Kernel\Command;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Brings the database schema in line with the mapping (015-000-0019).
 *
 * ── What this replaces ────────────────────────────────────────────────────────────────
 *
 * `POST /system/do {"method":"updateDatabase"}` had an emergency lock in front of it: if the
 * schema was broken badly enough that loading user and token failed, the authentication error was
 * swallowed and the request ran WITHOUT a user and WITHOUT the admin check. The reasoning was
 * sound — the path that repairs the schema must not be locked out by the broken schema — but the
 * consequence was an endpoint that anyone could trigger, at a moment of their choosing, that
 * applies the full diff including drops.
 *
 * The endpoint still exists and still needs an admin. What is gone is the door beside it. Schema
 * repair belongs to whoever has shell access, not to whoever can reach the port.
 *
 * ── Why it shows before it acts ───────────────────────────────────────────────────────
 *
 * Without `--force` it prints the statements and changes nothing. `updateSchema()` under ORM 3
 * applies the full diff — a mapping that is merely incomplete produces `DROP TABLE` for
 * everything it does not know about, and that was exactly the damage the open endpoint could do.
 * A command that acts only when told to is the same guarantee, in the operator's hands.
 */
class SchemaUpdateCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('appcms:schema:update')
            ->setDescription('Brings the database schema in line with the mapping — shows the statements, applies them with --force')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Actually run the statements instead of only showing them')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->application();
        $em  = $app['orm.em'];

        /*
         * Said plainly rather than as a TypeError out of Doctrine.
         *
         * `orm.em` stays null while the instance is not installed — no database credentials in
         * `custom/config.php`. That is a state somebody reaching for this command may well be in,
         * and a stack trace from a constructor is the wrong way to learn it.
         */
        if (!$em instanceof EntityManagerInterface) {
            $output->writeln('<error>No database connection: custom/config.php has no credentials yet.</error>');
            $output->writeln('<comment>Run appcms:install first.</comment>');

            return 1;
        }

        $schemaTool = new SchemaTool($em);
        $classes    = $em->getMetadataFactory()->getAllMetadata();

        if ($classes === array()) {
            $output->writeln('<error>No mapping metadata found — nothing could be compared.</error>');

            return 1;
        }

        $statements = $schemaTool->getUpdateSchemaSql($classes);

        if ($statements === array()) {
            $output->writeln('<info>The schema already matches the mapping. Nothing to do.</info>');

            return 0;
        }

        foreach ($statements as $statement) {
            $output->writeln($statement.';');
        }

        if (!$input->getOption('force')) {
            $output->writeln('');
            $output->writeln(sprintf(
                '<comment>%d statement(s) would run. Nothing was changed — repeat with --force.</comment>',
                count($statements)
            ));
            $output->writeln('<comment>Read them first: an incomplete mapping produces DROP statements.</comment>');

            return 0;
        }

        $schemaTool->updateSchema($classes);

        $output->writeln('');
        $output->writeln(sprintf('<info>%d statement(s) applied.</info>', count($statements)));

        return 0;
    }
}
