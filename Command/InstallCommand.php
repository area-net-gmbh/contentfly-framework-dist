<?php
namespace Areanet\PIM\Command;

use Areanet\PIM\Classes\Config\Adapter;
use Doctrine\ORM\Tools\SchemaTool;
use Areanet\PIM\Classes\Kernel\Command;
use Areanet\PIM\Classes\Kernel\Paths;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Installs Contentfly from the command line.
 *
 * Replaces the former `InstallController` together with its Twig form. The gain does not lie in
 * packaging the same steps differently, but in making them **scriptable and CI-capable**:
 * every input comes from an option or an environment variable, and no step forces an
 * interactive prompt.
 *
 * The steps are derived in `an_project/work/.../012-002-0001-installer-schritte-erfassen.md`.
 * The last of them — admin user and thumbnail sizes — already lives in
 * `$app['helper']->install()` and is called from here, not rebuilt.
 */
class InstallCommand extends Command
{
    /** Placeholder in custom/config.php that the installation replaces. */
    private const PLACEHOLDER_HOST = '$SET_DB_HOST';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('appcms:install')
            ->setDescription('Installiert Contentfly: schreibt die Konfiguration, legt das Schema an und erzeugt die Basisdaten')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'Datenbank-Host (Env: APPCMS_DB_HOST)')
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'Datenbank-Port (Env: APPCMS_DB_PORT)', '3306')
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'Datenbank-Name (Env: APPCMS_DB_NAME)')
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'Datenbank-Benutzer (Env: APPCMS_DB_USER)')
            ->addOption('db-pass', null, InputOption::VALUE_REQUIRED, 'Datenbank-Passwort (Env: APPCMS_DB_PASS)')
            ->addOption('db-strategy', null, InputOption::VALUE_REQUIRED, 'ID-Strategie: guid oder auto (Env: APPCMS_DB_STRATEGY)', 'auto')
            ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Passwort des Admin-Benutzers (Env: APPCMS_ADMIN_PASSWORD; Standard: admin)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur prüfen, nichts schreiben')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->application();

        // Step 0 — guard. An installed system is not installed again:
        // step 5 would overwrite the credentials in config.php and step 10 would
        // reset the admin password.
        if (Adapter::getConfig()->DB_HOST !== self::PLACEHOLDER_HOST) {
            $output->writeln('<error>Contentfly ist bereits installiert (custom/config.php trägt einen DB-Host).</error>');
            $output->writeln('Zum Neuaufsetzen die Platzhalter in custom/config.php wiederherstellen.');

            return 1;
        }

        $db = array(
            'host'     => $this->value($input, 'db-host', 'APPCMS_DB_HOST'),
            'port'     => (int) ($this->value($input, 'db-port', 'APPCMS_DB_PORT') ?: 3306),
            'name'     => $this->value($input, 'db-name', 'APPCMS_DB_NAME'),
            'user'     => $this->value($input, 'db-user', 'APPCMS_DB_USER'),
            'pass'     => $this->value($input, 'db-pass', 'APPCMS_DB_PASS'),
            'strategy' => strtolower((string) $this->value($input, 'db-strategy', 'APPCMS_DB_STRATEGY')),
        );

        $errors = $this->check($db, !$input->getOption('dry-run'));
        if (count($errors)) {
            $output->writeln('<error>Die Installation kann nicht starten:</error>');
            foreach ($errors as $context => $message) {
                $output->writeln(sprintf('  - <comment>%s</comment>: %s', $context, $message));
            }

            return 1;
        }

        if ($input->getOption('dry-run')) {
            $output->writeln('<info>Alle Prüfungen bestanden. Es wurde nichts geschrieben (--dry-run).</info>');

            return 0;
        }

        try {
            $this->writeConfig($db);
            $output->writeln('custom/config.php geschrieben.');

            $em = $this->bootDoctrine($app, $db);
            $output->writeln('Doctrine mit den neuen Zugangsdaten verbunden.');

            $schemaTool = new SchemaTool($em);
            $schemaTool->updateSchema($em->getMetadataFactory()->getAllMetadata());
            $output->writeln('Datenbankschema angelegt.');

            $app['helper']->install($em);
            $this->setAdminPassword($input, $em);
            $em->flush();
            $output->writeln('Basisdaten angelegt.');
        } catch (\Exception $e) {
            $output->writeln('<error>Die Installation ist fehlgeschlagen: '.$e->getMessage().'</error>');
            $output->writeln('<comment>custom/config.php trägt jetzt möglicherweise Zugangsdaten, ohne dass das Schema steht.</comment>');
            $output->writeln('<comment>Vor einem neuen Versuch die Platzhalter dort wiederherstellen.</comment>');

            return 1;
        }

        $password = $this->value($input, 'admin-password', 'APPCMS_ADMIN_PASSWORD') ?: 'admin';
        $output->writeln('<info>Contentfly wurde installiert. Login: admin / '.($password === 'admin' ? 'admin (Standard — bitte ändern)' : '<das gesetzte Passwort>').'</info>');

        return 0;
    }

    /** Option, otherwise environment variable, otherwise null. */
    private function value(InputInterface $input, string $option, string $env)
    {
        $value = $input->getOption($option);
        if ($value !== null && $value !== '') {
            return $value;
        }

        $fromEnv = getenv($env);

        return $fromEnv === false || $fromEnv === '' ? null : $fromEnv;
    }

    /**
     * Steps 1–4: inputs, chmod, write permissions, database connection.
     *
     * Collects **all** errors instead of aborting at the first one — whoever scripts an
     * installation does not want to fail five times in a row on yet another small thing.
     *
     * @param bool $mayChmod Whether the permissions may be set. Not under --dry-run:
     *                       a run that reports "nothing written" must not change any
     *                       file permissions either. Without chmod, only the current state
     *                       is checked.
     */
    private function check(array $db, bool $mayChmod = true): array
    {
        $errors = array();

        foreach (array('host' => 'db-host', 'name' => 'db-name', 'user' => 'db-user', 'pass' => 'db-pass') as $key => $option) {
            if ($db[$key] === null) {
                $errors[$option] = 'fehlt (Option --'.$option.' oder Umgebungsvariable setzen)';
            }
        }

        if ($db['port'] < 1 || $db['port'] > 65535) {
            $errors['db-port'] = 'ist kein gültiger Port: '.$db['port'];
        }

        if (!in_array($db['strategy'], array('guid', 'auto'), true)) {
            $errors['db-strategy'] = 'muss "guid" oder "auto" sein, war: "'.$db['strategy'].'"';
        }

        if (!$this->isFunctionEnabled('chmod')) {
            $errors['chmod'] = 'PHP-Funktion chmod() ist deaktiviert.';
        }

        if ($mayChmod) {
            @chmod(Paths::custom().'/config.php', 0775);
            @chmod(Paths::data().'/files', 0775);
            @chmod(Paths::data().'/cache', 0775);
        }

        if (!is_writable(Paths::custom().'/config.php')) {
            $errors['custom/config.php'] = $mayChmod
                ? 'ist nicht schreibbar.'
                : 'ist nicht schreibbar (unter --dry-run werden die Rechte nicht gesetzt).';
        }

        if (!isset($errors['db-host']) && !isset($errors['db-name'])) {
            try {
                new \PDO('mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['name'], $db['user'], $db['pass']);
            } catch (\Exception $e) {
                // The database itself is not created — it must already exist.
                $errors['database'] = $e->getMessage();
            }
        }

        return $errors;
    }

    /** Step 5: replace the placeholders in custom/config.php with the real values. */
    private function writeConfig(array $db): void
    {
        $path = Paths::custom().'/config.php';
        $data = file_get_contents($path);

        $data = str_replace(self::PLACEHOLDER_HOST, $db['host'], $data);
        $data = str_replace("'\$SET_DB_PORT'", (string) $db['port'], $data);
        $data = str_replace('$SET_DB_NAME', $db['name'], $data);
        $data = str_replace('$SET_DB_USER', $db['user'], $data);
        $data = str_replace('$SET_DB_PASS', $db['pass'], $data);
        $data = str_replace("'\$SET_DB_GUID_STRATEGY'", $db['strategy'] === 'guid' ? 'true' : 'false', $data);

        if (file_put_contents($path, $data) === false) {
            throw new \Exception($path.' konnte nicht geschrieben werden.');
        }
    }

    /**
     * Steps 6–8: ID strategy as constants, Doctrine with the new credentials,
     * TypeManager including the system types.
     *
     * Necessary because `bootstrap.php` registers neither DBAL nor ORM when
     * `is_installed == false` — after all, the configuration was still empty when this command
     * started.
     */
    private function bootDoctrine($app, array $db)
    {
        if ($db['strategy'] === 'guid') {
            define('APPCMS_ID_TYPE', 'string');
            define('APPCMS_ID_STRATEGY', 'CUSTOM');
        } else {
            define('APPCMS_ID_TYPE', 'integer');
            define('APPCMS_ID_STRATEGY', 'AUTO');
        }

        /*
         * The connection, built by hand (009-002-0002) — as in the regular bootstrap.
         *
         * This used to be `$app->register(new DoctrineServiceProvider(), …)`. The provider went
         * away with Silex; what it created were `$app['dbs']` and `$app['db']`, and both are
         * read further down and in bin/console.php.
         */
        $app['dbs'] = function () use ($db) {
            return array('pim' => DriverManager::getConnection(array(
                'driver'   => 'pdo_mysql',
                'host'     => $db['host'],
                'port'     => $db['port'],
                'dbname'   => $db['name'],
                'user'     => $db['user'],
                'password' => $db['pass'],
                'charset'  => Adapter::getConfig()->DB_CHARSET,
                'collate'  => Adapter::getConfig()->DB_COLLATE,
            )));
        };

        $app['db'] = function ($app) {
            $verbindungen = $app['dbs'];

            return reset($verbindungen);
        };

        // The same construction as in the regular bootstrap — see 006-002-0005.
        // If it diverges, the installer installs against a different schema than the one
        // the application uses later.
        $app['orm.em'] = function ($app) {
            return \Areanet\PIM\Classes\ORM\EntityManagerFactory::create(
                $app['dbs']['pim'],
                array(
                    array('namespace' => 'Areanet\PIM\Entity', 'path' => Paths::frameworkEntities()),
                    array('namespace' => 'Custom\Entity',       'path' => Paths::projectEntities()),
                ),
                Paths::data().'/cache/doctrine',
                true,
                array('Find_In_Set' => '\Areanet\PIM\Classes\ORM\Query\Mysql\FindInSet')
            );
        };

        $app['typeManager'] = function ($app) {
            return new \Areanet\PIM\Classes\Manager\TypeManager($app);
        };

        foreach (Adapter::getConfig()->APP_SYSTEM_TYPES as $systemType) {
            $app['typeManager']->registerType(new $systemType($app));
        }

        // The same quote strategy as in the regular bootstrap. Without it, column names
        // stay unquoted — and `groups` in pim_user has been a reserved word since
        // MySQL 8.0.2, so the INSERT fails with a syntax error. On MySQL 5.7 this was
        // never noticed; the InstallController had the same gap.
        $app['orm.em']->getConfiguration()->setQuoteStrategy(
            new \Areanet\PIM\Classes\ORM\Mapping\ContentflyQuoteStrategy()
        );

        return $app['orm.em'];
    }

    /**
     * Sets the admin password if one was passed.
     *
     * `helper->install()` creates the admin with `admin`/`admin`. Through a web interface with
     * a subsequent login that was acceptable; a scriptable installation should be able to set a
     * real password without anyone having to change it by hand afterwards.
     */
    private function setAdminPassword(InputInterface $input, $em): void
    {
        $password = $this->value($input, 'admin-password', 'APPCMS_ADMIN_PASSWORD');
        if ($password === null) {
            return;
        }

        $admin = $em->getRepository('Areanet\PIM\Entity\User')->findOneBy(array('alias' => 'admin'));
        if ($admin) {
            $admin->setPass($password);
            $em->persist($admin);
        }
    }

    /** Own name: Command::isEnabled() is already taken as a public method in Symfony. */
    private function isFunctionEnabled($func): bool
    {
        return is_callable($func) && false === stripos(ini_get('disable_functions'), $func);
    }
}
