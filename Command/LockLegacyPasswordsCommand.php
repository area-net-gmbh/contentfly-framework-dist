<?php
namespace Areanet\PIM\Command;

use Areanet\PIM\Classes\Kernel\Command;
use Areanet\PIM\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Locks every account still carrying a SHA-256 password hash (000-000-0103).
 *
 * ── Why a lock and not a migration ────────────────────────────────────────────────────
 *
 * A hash cannot be converted without knowing the password — that is what a hash is for. So
 * `013-001-0001` chose the only path that needs no password: replace the old hash at the next
 * login, where the plaintext is briefly at hand. Whoever has not logged in since still carries
 * theirs, and no amount of batch processing changes that.
 *
 * What remains is the forced reset. The decision was taken in `015-000-0012` and written into
 * the register there; this command carries it out.
 *
 * ── Why it matters now and did not before ─────────────────────────────────────────────
 *
 * `015-000-0012` closed the path that let a hash be read out through the API. A SHA-256 hash
 * without a work factor stays the weakest point of any installation that still has one: every
 * leak somewhere else — a backup, a database dump — makes it crackable at GPU speed. The API is
 * no longer the way in; the hash is still the prize.
 *
 * ── What it does NOT do ───────────────────────────────────────────────────────────────
 *
 * It does not deactivate the account and it does not delete anything. `lockPassword()` writes the
 * asterisk that `isPass()` refuses outright (`013-004-0002`), so the account exists, keeps its
 * rights and its `pim_log` history, and needs a new password to be usable. An admin sets one; the
 * output says so, because an operator who locks accounts without knowing the way back will not
 * run this a second time.
 *
 * Accounts that are ALREADY locked are left alone and counted separately — running twice must
 * not look like it found new ones.
 */
class LockLegacyPasswordsCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('appcms:security:lock-legacy-passwords')
            ->setDescription('Locks accounts that still carry a SHA-256 password hash')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count, lock nobody')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app    = $this->application();
        $em     = $app['orm.em'];
        $dryRun = (bool) $input->getOption('dry-run');

        // Said plainly rather than as a fatal out of Doctrine — same as appcms:schema:update.
        if (!$em instanceof EntityManagerInterface) {
            $output->writeln('<error>No database connection: custom/config.php has no credentials yet.</error>');
            $output->writeln('<comment>Run appcms:install first.</comment>');

            return 1;
        }

        /*
         * EVERY user is loaded and the format decided in PHP, not in SQL.
         *
         * `isLegacyFormat()` is the one place that says what "old format" means — it asks whether
         * the hash starts with `$`. A `LIKE '$%'` in the query would be a second answer to the
         * same question, in a second language, and the two would drift apart the day the format
         * changes again. The table of users is small enough that this costs nothing.
         */
        $users = $em->getRepository(User::class)->findAll();

        $locked       = 0;
        $alreadyLocked = 0;
        $affected     = array();

        foreach ($users as $user) {
            if ($user->isPasswordLocked()) {
                $alreadyLocked++;
                continue;
            }

            if (!$user->isLegacyFormat()) {
                continue;
            }

            $locked++;
            $affected[] = (string) $user->getAlias();

            if (!$dryRun) {
                $user->lockPassword();
            }
        }

        if (!$dryRun && $locked > 0) {
            $em->flush();
        }

        $this->report($output, $locked, $alreadyLocked, $affected, $dryRun);

        return 0;
    }

    /** @param list<string> $affected */
    private function report(OutputInterface $output, int $locked, int $alreadyLocked, array $affected, bool $dryRun): void
    {
        if ($locked === 0) {
            $output->writeln('<info>✓ No account carries a SHA-256 hash any more. Nothing to do.</info>');

            if ($alreadyLocked > 0) {
                $output->writeln(sprintf('<comment>  (%d account(s) already have a locked password.)</comment>', $alreadyLocked));
            }

            return;
        }

        $output->writeln(sprintf(
            '%s %d account(s) carry a SHA-256 hash%s:',
            $dryRun ? '→' : '✓',
            $locked,
            $dryRun ? ' (--dry-run, nobody was locked)' : ' and were locked'
        ));

        foreach ($affected as $alias) {
            $output->writeln('    '.$alias);
        }

        if ($alreadyLocked > 0) {
            $output->writeln(sprintf('<comment>  (%d further account(s) were already locked.)</comment>', $alreadyLocked));
        }

        $output->writeln('');

        if ($dryRun) {
            $output->writeln('<comment>Run again without --dry-run to lock them.</comment>');

            return;
        }

        /*
         * THE WAY BACK, in the output and not only in the docs. Whoever locks accounts and is left
         * without a way to unlock them does not run this command a second time.
         */
        $output->writeln('<comment>These accounts cannot log in until an admin sets a new password</comment>');
        $output->writeln('<comment>for them — over /api/update on PIM\\User, field "pass". The accounts</comment>');
        $output->writeln('<comment>themselves are untouched: rights, group and log history remain.</comment>');
    }
}
