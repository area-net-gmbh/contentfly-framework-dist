<?php
namespace Areanet\PIM\Command;

use Areanet\PIM\Classes\Config\Adapter;
use Doctrine\ORM\Tools\SchemaTool;
use Areanet\PIM\Classes\Kernel\Command;
use Areanet\PIM\Classes\Kernel\Pfade;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Installiert Contentfly von der Kommandozeile.
 *
 * Ersetzt den bisherigen `InstallController` samt Twig-Maske. Der Gewinn liegt nicht darin,
 * dieselben Schritte anders zu verpacken, sondern darin, dass sie **skript- und CI-fähig**
 * werden: Jede Eingabe kommt über eine Option oder eine Umgebungsvariable, kein Schritt
 * erzwingt eine Rückfrage.
 *
 * Die Schritte sind in `an_project/work/.../012-002-0001-installer-schritte-erfassen.md`
 * hergeleitet. Der letzte davon — Admin-Benutzer und Thumbnail-Größen — liegt bereits in
 * `$app['helper']->install()` und wird von hier aufgerufen, nicht nachgebaut.
 */
class InstallCommand extends Command
{
    /** Platzhalter in custom/config.php, die die Installation ersetzt. */
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
        $app = $this->anwendung();

        // Schritt 0 — Guard. Ein installiertes System wird nicht erneut installiert:
        // Schritt 5 würde die Zugangsdaten in config.php überschreiben und Schritt 10
        // das Admin-Passwort zurücksetzen.
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

    /** Option, sonst Umgebungsvariable, sonst null. */
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
     * Schritte 1–4: Eingaben, chmod, Schreibrechte, Datenbankverbindung.
     *
     * Sammelt **alle** Fehler statt beim ersten abzubrechen — wer eine Installation
     * skriptet, will nicht fünfmal hintereinander an einer neuen Kleinigkeit scheitern.
     *
     * @param bool $mayChmod Ob die Rechte gesetzt werden dürfen. Unter --dry-run nicht:
     *                       ein Lauf, der „nichts geschrieben" meldet, darf auch keine
     *                       Dateirechte verändern. Ohne chmod wird nur geprüft, was ist.
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
            @chmod(Pfade::custom().'/config.php', 0775);
            @chmod(Pfade::daten().'/files', 0775);
            @chmod(Pfade::daten().'/cache', 0775);
        }

        if (!is_writable(Pfade::custom().'/config.php')) {
            $errors['custom/config.php'] = $mayChmod
                ? 'ist nicht schreibbar.'
                : 'ist nicht schreibbar (unter --dry-run werden die Rechte nicht gesetzt).';
        }

        if (!isset($errors['db-host']) && !isset($errors['db-name'])) {
            try {
                new \PDO('mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['name'], $db['user'], $db['pass']);
            } catch (\Exception $e) {
                // Die Datenbank selbst wird nicht angelegt — sie muss existieren.
                $errors['database'] = $e->getMessage();
            }
        }

        return $errors;
    }

    /** Schritt 5: Platzhalter in custom/config.php durch die echten Werte ersetzen. */
    private function writeConfig(array $db): void
    {
        $path = Pfade::custom().'/config.php';
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
     * Schritte 6–8: ID-Strategie als Konstanten, Doctrine mit den neuen Zugangsdaten,
     * TypeManager samt System-Typen.
     *
     * Nötig, weil `bootstrap.php` bei `is_installed == false` weder DBAL noch ORM
     * registriert — beim Start dieses Commands war die Konfiguration ja noch leer.
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
         * Die Verbindung, selbst gebaut (009-002-0002) — wie im regulaeren Bootstrap.
         *
         * Hier stand `$app->register(new DoctrineServiceProvider(), …)`. Der Provider ist mit
         * Silex entfallen; was er anlegte, waren `$app['dbs']` und `$app['db']`, und beide
         * werden weiter unten und in bin/console.php gelesen.
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

        // Dieselbe Konstruktion wie im regulaeren Bootstrap — siehe 006-002-0005.
        // Weicht sie ab, installiert der Installer gegen ein anderes Schema, als die
        // Anwendung spaeter benutzt.
        $app['orm.em'] = function ($app) {
            return \Areanet\PIM\Classes\ORM\EntityManagerFactory::erzeugen(
                $app['dbs']['pim'],
                array(
                    array('namespace' => 'Areanet\PIM\Entity', 'path' => Pfade::entitiesDesFrameworks()),
                    array('namespace' => 'Custom\Entity',       'path' => Pfade::entitiesDesProjekts()),
                ),
                Pfade::daten().'/cache/doctrine',
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

        // Dieselbe Quote-Strategie wie im regulären Bootstrap. Ohne sie bleiben
        // Spaltennamen ungequotet — und `groups` in pim_user ist seit MySQL 8.0.2 ein
        // reserviertes Wort, das INSERT scheitert an einem Syntaxfehler. Auf MySQL 5.7
        // fiel das nie auf; der InstallController hatte dieselbe Lücke.
        $app['orm.em']->getConfiguration()->setQuoteStrategy(
            new \Areanet\PIM\Classes\ORM\Mapping\ContentflyQuoteStrategy()
        );

        return $app['orm.em'];
    }

    /**
     * Setzt das Admin-Passwort, wenn eines übergeben wurde.
     *
     * `helper->install()` legt den Admin mit `admin`/`admin` an. Über eine Weboberfläche mit
     * anschließendem Login war das vertretbar; eine skriptbare Installation soll ein echtes
     * Passwort setzen können, ohne dass jemand es nachträglich von Hand ändert.
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

    /** Eigener Name: Command::isEnabled() ist in Symfony bereits public belegt. */
    private function isFunctionEnabled($func): bool
    {
        return is_callable($func) && false === stripos(ini_get('disable_functions'), $func);
    }
}
