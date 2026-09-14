<?php
namespace Areanet\PIM\Command;

use Areanet\PIM\Classes\Kernel\Command;
use Areanet\PIM\Classes\Security\FieldEncryption;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Moves encrypted field values from the old AES-CBC format to XChaCha20-Poly1305
 * (010-004-0003).
 *
 * ── What for ─────────────────────────────────────────────────────────────────────────
 *
 * Since 010-004-0002 the framework writes AEAD and reads both. An existing value therefore stays
 * readable and is converted in passing the next time it is written. Whoever does not want to wait
 * until every record has been touched once runs this command.
 *
 * ── --dry-run is not decoration ──────────────────────────────────────────────────────
 *
 * Later on, the command runs in other people's existing projects on their data (Epic 007). A
 * migration that cannot be inspected beforehand rightly does not get executed. The dry run
 * counts what it would do and touches nothing.
 *
 * ── The way back ─────────────────────────────────────────────────────────────────────
 *
 * CREATE A BACKUP OF THE DATABASE BEFORE THE RUN. That is the way back, and it is the only
 * one: re-encrypted values cannot be converted back without applying the old key again — and
 * getting away from exactly that is the point.
 *
 * BUT: an ABORTED run does no harm. Both formats stay readable, and the command skips what has
 * already been converted. Whoever starts again after an error picks up where it stopped. The way
 * back is therefore only needed if someone ran with the WRONG SECURITY_CIPHER_KEY — then the
 * values are not broken, but encrypted with a key nobody wanted.
 *
 * ── Why DBAL and not the EntityManager ───────────────────────────────────────────────
 *
 * The command works on columns, not on objects. Through the EntityManager it would have to load
 * every entity, would be saddled with lifecycle callbacks (`Base::updateModifiedDatetime()` would
 * update `modified` even though nothing changes in business terms) and would hold the entire
 * dataset in memory. Through DBAL it is batches of fixed size and one UPDATE per row.
 *
 * ── How the fields are found ─────────────────────────────────────────────────────────
 *
 * Through the schema, not through a fixed list — otherwise it would not find a project's fields,
 * and those are exactly the reason for the command. In the framework itself there is currently
 * NO field with `encoded: true`; a run here consequently reports nothing to do.
 */
class ReencryptCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('appcms:security:reencrypt')
            ->setDescription('Converts encrypted field values to the AEAD format')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count, write nothing')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows per batch', '500')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app       = $this->application();
        $dryRun    = (bool) $input->getOption('dry-run');
        $batchSize = max(1, (int) $input->getOption('batch'));

        $fields = $this->affectedFields($app['schema'], $app['orm.em'], $app['helper']);

        if (!$fields) {
            $output->writeln('<comment>No field with encoded: true — there is nothing to re-encrypt.</comment>');

            return 0;
        }

        if ($dryRun) {
            $output->writeln('<comment>--dry-run: nothing is written.</comment>');
        }

        $total = array('checked' => 0, 'reencrypted' => 0, 'skipped' => 0);

        foreach ($fields as $field) {
            $result = $this->reencryptColumn(
                $app['db'],
                $field['table'],
                $field['column'],
                $field['key_column'],
                $dryRun,
                $batchSize
            );

            $output->writeln(sprintf(
                '  %-40s %5d checked, %5d re-encrypted, %5d already new',
                $field['entity'].'::'.$field['field'],
                $result['checked'],
                $result['reencrypted'],
                $result['skipped']
            ));

            foreach ($total as $k => $v) {
                $total[$k] = $v + $result[$k];
            }
        }

        $output->writeln(sprintf(
            '%s %d values checked, %d re-encrypted, %d were already in the new format.',
            $dryRun ? '→' : '✓',
            $total['checked'],
            $total['reencrypted'],
            $total['skipped']
        ));

        return 0;
    }

    /**
     * The fields with `encoded: true`, resolved to table and column.
     *
     * PUBLIC SO THAT IT IS TESTABLE. There is no such field in the tree — a test that calls the
     * command from outside could therefore only confirm that it does nothing. With a schema
     * passed in, it can be verified that it would find the right thing.
     *
     * @param array<string,array<string,mixed>> $schema
     *
     * @return array<int,array{entity:string,field:string,table:string,column:string,key_column:string}>
     */
    public function affectedFields(array $schema, EntityManagerInterface $em, $helper): array
    {
        $fields = array();

        foreach ($schema as $entity => $definition) {
            if ($entity === '_hash' || empty($definition['properties'])) {
                continue;
            }

            foreach ($definition['properties'] as $field => $property) {
                if (empty($property['encoded'])) {
                    continue;
                }

                $class    = $helper->getFullEntityName($entity);
                $metadata = $em->getClassMetadata($class);

                $fields[] = array(
                    'entity'     => $entity,
                    'field'      => $field,
                    'table'      => $metadata->getTableName(),
                    'column'     => $metadata->getColumnName($field),
                    'key_column' => $metadata->getSingleIdentifierColumnName(),
                );
            }
        }

        return $fields;
    }

    /**
     * Re-encrypts one column, in batches.
     *
     * PUBLIC FOR THE SAME REASON as above: without a field with `encoded: true` in the tree, this
     * is the only place where the operation can be demonstrated against a real database.
     *
     * ONE BATCH IS ONE TRANSACTION. If it aborts, no half re-encrypted batch is left behind.
     * Across batches an abort is harmless anyway — whatever has already been converted is
     * skipped on the next run.
     *
     * @return array{checked:int,reencrypted:int,skipped:int}
     */
    public function reencryptColumn(
        Connection $db,
        string $table,
        string $column,
        string $keyColumn,
        bool $dryRun,
        int $batchSize
    ): array {
        $encryption = new FieldEncryption();
        $counts     = array('checked' => 0, 'reencrypted' => 0, 'skipped' => 0);
        $lastKey    = null;

        while (true) {
            $query = sprintf(
                'SELECT %1$s AS pk, %2$s AS value FROM %3$s WHERE %2$s IS NOT NULL AND %2$s <> \'\'%4$s ORDER BY %1$s ASC LIMIT %5$d',
                $db->quoteIdentifier($keyColumn),
                $db->quoteIdentifier($column),
                $db->quoteIdentifier($table),
                $lastKey === null ? '' : sprintf(' AND %s > ?', $db->quoteIdentifier($keyColumn)),
                $batchSize
            );

            $rows = $db->fetchAllAssociative($query, $lastKey === null ? array() : array($lastKey));

            if (!$rows) {
                break;
            }

            $db->beginTransaction();

            try {
                foreach ($rows as $row) {
                    $lastKey = $row['pk'];
                    $counts['checked']++;

                    if ($encryption->isNewFormat((string) $row['value'])) {
                        $counts['skipped']++;
                        continue;
                    }

                    $plaintext = $encryption->decrypt((string) $row['value']);

                    if ($plaintext === false) {
                        throw new \RuntimeException(sprintf(
                            'The value in %s.%s (%s = %s) cannot be decrypted. '
                            .'Is the correct SECURITY_CIPHER_KEY set in custom/config.php?',
                            $table,
                            $column,
                            $keyColumn,
                            (string) $row['pk']
                        ));
                    }

                    $counts['reencrypted']++;

                    if (!$dryRun) {
                        $db->update(
                            $table,
                            array($column => $encryption->encrypt($plaintext)),
                            array($keyColumn => $row['pk'])
                        );
                    }
                }

                $db->commit();
            } catch (\Throwable $error) {
                $db->rollBack();

                throw $error;
            }

            if (count($rows) < $batchSize) {
                break;
            }
        }

        return $counts;
    }
}
