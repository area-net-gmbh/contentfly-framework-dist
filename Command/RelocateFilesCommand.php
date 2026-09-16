<?php
namespace Areanet\PIM\Command;

use Areanet\PIM\Classes\Kernel\Command;
use Areanet\PIM\Classes\Kernel\Paths;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Moves files from the Contentfly 1.x layout into the one Contentfly 2 reads (000-000-0041).
 *
 * Contentfly 1.6 stored a file under `data/files/<path><id>/`, with `pim_file.path` holding a date
 * prefix such as `2026/06/`. Contentfly 2 reads `data/files/<id>/` and has no `path` field — the schema
 * update drops the column. Measured on the existing project UFP (007-005-0003): all 41 files had a
 * path, none was delivered after a migration by the guide, and once the column is gone the information
 * where they lay is gone with it.
 *
 * DECIDED ON 2026-09-15: one layout, reached once, instead of a legacy branch in the file backend for
 * as long as the framework lives. That makes the order binding — **this command runs before the
 * schema update**, because it needs the column.
 *
 * It only reads the database. It moves whole folders, so the thumbnails and variants that live next
 * to the original move along. A second run finds everything in place.
 *
 * What it does not touch, and reports: a row whose folder is missing, a row whose target exists while
 * the source exists too (a conflict a person has to look at), and a `path` that is not a plain relative
 * directory prefix.
 */
class RelocateFilesCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('appcms:files:relocate')
            ->setDescription('Moves files from the Contentfly 1.x layout data/files/<path><id>/ to data/files/<id>/ — run before the schema update')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report, move nothing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->relocate($this->application()['database'], Paths::data() . '/files', $dryRun);

        if ($result['without_column']) {
            $output->writeln('<comment>pim_file has no column path — nothing to relocate (the schema is already on Contentfly 2, or the files were never stored by date).</comment>');

            return 0;
        }

        foreach ($result['problems'] as $problem) {
            $output->writeln('  <error>' . $problem . '</error>');
        }

        $output->writeln(sprintf(
            '%s %d moved, %d already in place, %d without path, %d missing, %d conflicts, %d rejected paths.',
            $dryRun ? 'Dry run:' : 'Done:',
            $result['moved'], $result['in_place'], $result['without_path'], $result['missing'], $result['conflicts'], $result['rejected']
        ));

        if (!$dryRun && $result['moved'] > 0) {
            $output->writeln('Now run the schema update — the column path is no longer needed.');
        }

        return $result['conflicts'] || $result['rejected'] || $result['failed'] ? 1 : 0;
    }

    /**
     * @return array{without_column:bool,moved:int,in_place:int,without_path:int,missing:int,conflicts:int,rejected:int,failed:int,problems:list<string>}
     */
    public function relocate(Connection $db, string $filesDir, bool $dryRun, string $table = 'pim_file'): array
    {
        $result = array('without_column' => false, 'moved' => 0, 'in_place' => 0, 'without_path' => 0, 'missing' => 0,
                        'conflicts' => 0, 'rejected' => 0, 'failed' => 0, 'problems' => array());

        $columns = array_map('strtolower', array_keys($db->createSchemaManager()->listTableColumns($table)));
        if (!in_array('path', $columns, true)) {
            $result['without_column'] = true;

            return $result;
        }

        $filesDir = rtrim($filesDir, '/');
        $rows     = $db->executeQuery('SELECT id, path FROM ' . $db->quoteIdentifier($table) . ' ORDER BY id')->fetchAllAssociative();

        foreach ($rows as $row) {
            $id   = (string) $row['id'];
            $path = trim((string) ($row['path'] ?? ''));

            if ($path === '') {
                $result['without_path']++;
                continue;
            }

            // A date prefix like `2026/06/` — nothing that climbs out of data/files or points elsewhere.
            if (!preg_match('#^[A-Za-z0-9_\-]+(?:/[A-Za-z0-9_\-]+)*/?$#', $path) || !preg_match('#^[A-Za-z0-9_\-]+$#', $id)) {
                $result['rejected']++;
                $result['problems'][] = sprintf('%s: path "%s" is not a plain relative prefix — left alone', $id, $path);
                continue;
            }

            $from = $filesDir . '/' . rtrim($path, '/') . '/' . $id;
            $to   = $filesDir . '/' . $id;

            if (is_dir($to)) {
                if (is_dir($from)) {
                    $result['conflicts']++;
                    $result['problems'][] = sprintf('%s: both %s and %s exist — left alone, compare them by hand', $id, $this->relative($filesDir, $from), $this->relative($filesDir, $to));
                } else {
                    $result['in_place']++;
                }
                continue;
            }

            if (!is_dir($from)) {
                $result['missing']++;
                $result['problems'][] = sprintf('%s: %s does not exist — the file was already missing', $id, $this->relative($filesDir, $from));
                continue;
            }

            if ($dryRun) {
                $result['moved']++;
                continue;
            }

            if (@rename($from, $to)) {
                $result['moved']++;
                $this->removeEmptyParents(dirname($from), $filesDir);
            } else {
                $result['failed']++;
                $result['problems'][] = sprintf('%s: moving %s failed', $id, $this->relative($filesDir, $from));
            }
        }

        return $result;
    }

    /** The emptied date folders (`2026/06/`, then `2026/`) go; data/files itself stays. */
    private function removeEmptyParents(string $directory, string $filesDir): void
    {
        while ($directory !== $filesDir && str_starts_with($directory, $filesDir . '/') && is_dir($directory)
            && count(scandir($directory)) === 2) {
            rmdir($directory);
            $directory = dirname($directory);
        }
    }

    private function relative(string $filesDir, string $path): string
    {
        return 'data/files/' . substr($path, strlen($filesDir) + 1) . '/';
    }
}
