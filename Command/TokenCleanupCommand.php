<?php
namespace Areanet\PIM\Command;

use Areanet\PIM\Classes\Config\Adapter;
use Areanet\PIM\Classes\Kernel\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clears expired login tokens from `pim_token` (000-000-0015) and obsolete revocation-list
 * entries from `pim_revoked_token` (013-003-0003).
 *
 * ── Against what ─────────────────────────────────────────────────────────────────────
 *
 * Every login creates a row. Until now, cleanup only happened LAZILY, in
 * `BaseControllerProvider::checkToken()`: if an expired token is presented once more, it
 * disappears. A token that nobody uses again — the normal case when the browser is closed —
 * stayed forever. There was no cleanup run, no command and no endpoint.
 *
 * ── Why this cannot wait for Epic 013 ────────────────────────────────────────────────
 *
 * The task left open whether 013-003 replaces the token table with JWT. It does not: the story
 * explicitly keeps the opaque DB token as the REFRESH token — "exactly the mechanism that
 * already exists". So the table stays, and with it the growth. This came true with
 * 013-003-0001: a refresh token IS a `pim_token` row, just with `purpose = refresh`. It is
 * subject to the same time limit and is cleaned up by this command as well.
 *
 * ── What counts as expired ───────────────────────────────────────────────────────────
 *
 * The same calculation as in checkToken(), so that nothing is dropped here that would still be
 * valid there:
 *
 *   - `modified` older than the time limit. It is counted from the last use, not from the
 *     login — checkToken() writes `modified` back on every call.
 *   - The limit comes from the user's group (`tokenTimeout`, in minutes), otherwise from
 *     `APP_TOKEN_TIMEOUT` (in seconds).
 *   - A token WITH a `referrer` is an API token and does not expire. checkToken() exempts it
 *     as well; it is removed via `deleteToken`, not by time.
 *   - If `APP_CHECK_TOKEN_TIMEOUT` is off, nothing expires at all — then this command does not
 *     clear anything either, otherwise it would delete valid sessions.
 *
 * ── --dry-run ────────────────────────────────────────────────────────────────────────
 *
 * A cleanup run that cannot be inspected beforehand does not get executed. The default is
 * therefore deliberately NOT dry — whoever hooks the command into a cron job should not
 * discover that it never did anything.
 */
class TokenCleanupCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('appcms:token:cleanup')
            ->setDescription('Removes expired login tokens from pim_token')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count, delete nothing')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->application();
        $em  = $app['orm.em'];

        if (!Adapter::getConfig()->APP_CHECK_TOKEN_TIMEOUT) {
            $output->writeln('<comment>APP_CHECK_TOKEN_TIMEOUT is off — tokens do not expire, nothing is removed.</comment>');

            return 0;
        }

        $dryRun  = (bool) $input->getOption('dry-run');
        $now     = new \DateTime();
        $default = (int) Adapter::getConfig()->APP_TOKEN_TIMEOUT;

        $tokens = $em->createQuery(
            "SELECT t FROM Areanet\\PIM\\Entity\\Token t WHERE t.referrer IS NULL OR t.referrer = ''"
        )->getResult();

        $expired = 0;
        $checked = 0;

        foreach ($tokens as $entry) {
            $checked++;

            $user  = $entry->getUser();
            $limit = $default;

            if ($user && ($group = $user->getGroup()) && $group->getTokenTimeout()) {
                $limit = ((int) $group->getTokenTimeout()) * 60;
            }

            if (!$limit) {
                continue;
            }

            if (($now->getTimestamp() - $entry->getModified()->getTimestamp()) <= $limit) {
                continue;
            }

            $expired++;

            if (!$dryRun) {
                $em->remove($entry);
            }
        }

        /*
         * THE REVOCATION LIST RIDES ON THE SAME RUN (013-003-0003).
         *
         * An entry is obsolete as soon as the token it revokes would have expired anyway.
         * Clearing it here along with the rest is not an extra, but the reason the list stays
         * small — the customer project had such a list without expiry, and it was bound to grow
         * without limit.
         *
         * NO SECOND CLEANUP PATH: an operator who has this command in cron should not have to
         * find out that there is a second one.
         */
        $revoked = $em->createQuery(
            "SELECT s FROM Areanet\\PIM\\Entity\\RevokedToken s WHERE s.expiresAt < :now"
        )->setParameter('now', $now)->getResult();

        foreach ($revoked as $entry) {
            if (!$dryRun) {
                $em->remove($entry);
            }
        }

        if (!$dryRun) {
            $em->flush();
        }

        $output->writeln(sprintf(
            '%s %d of %d login tokens expired%s.',
            $dryRun ? '→' : '✓',
            $expired,
            $checked,
            $dryRun ? ' (--dry-run, nothing removed)' : ' and removed'
        ));

        $output->writeln(sprintf(
            '%s %d revocation list entries obsolete%s.',
            $dryRun ? '→' : '✓',
            count($revoked),
            $dryRun ? ' (--dry-run, nothing removed)' : ' and removed'
        ));

        return 0;
    }
}
