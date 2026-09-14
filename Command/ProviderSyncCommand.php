<?php
namespace Areanet\PIM\Command;

use Areanet\PIM\Classes\Kernel\Command;
use Areanet\PIM\Classes\Security\UserExistenceCheck;
use Areanet\PIM\Entity\User;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Keeps the provisioned users in line with their external system (013-005-0002).
 *
 * ── Against what ─────────────────────────────────────────────────────────────────────
 *
 * Whoever disappears from the directory can no longer get in — that follows on its own: the
 * provider does not find them and rejects the login. Their Contentfly account remains, however,
 * and with it
 *
 *   - a refresh token that keeps fetching fresh access JWTs until its time limit,
 *   - an opaque login token that stays valid until the timeout.
 *
 * A user whom HR has removed from the AD therefore keeps working — until a time limit expires
 * that nobody chose for this purpose.
 *
 * ── Chosen: deactivate, not delete ───────────────────────────────────────────────────
 *
 * `isActive` set to false. Three reasons:
 *
 *   It takes effect    The `UserLoader` rejects an inactive user (013-002-0001), and on
 *   immediately.       EVERY path — running access JWTs fail as well, because the user is
 *                      loaded on every request. The refresh endpoint rejects too
 *                      (013-003-0002).
 *   It is reversible.  Whoever dropped out of a group by accident is activated again.
 *                      A deleted row does not come back.
 *   `pim_log` keeps    its reference. A log whose user has disappeared can no longer tell
 *                      who acted.
 *
 * Explicitly NOT chosen: "ignore" — that is the very state this command was built against.
 *
 * ── Why a command and not something on the side ──────────────────────────────────────
 *
 * A user who has disappeared is precisely the one not logging in; that is the whole point. It
 * takes a trigger. The alternative would be to hook the reconciliation onto the next login of
 * SOMEONE ELSE — that loads unrelated work onto a request that knows nothing about it, and makes
 * the login time depend on the size of the directory.
 *
 * ── What it does not touch ───────────────────────────────────────────────────────────
 *
 * Users without a `loginManager` — they have a local password and are no business of any
 * external system. And providers that are not a `UserExistenceCheck`: they are counted and
 * skipped, visibly in the output.
 */
class ProviderSyncCommand extends Command
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('appcms:provider:sync')
            ->setDescription('Deactivates users that their external system no longer knows')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count, deactivate nobody')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app      = $this->application();
        $em       = $app['orm.em'];
        $registry = $app['loginProviders'];
        $dryRun   = (bool) $input->getOption('dry-run');

        $users = $em->createQuery(
            "SELECT u FROM Areanet\\PIM\\Entity\\User u
              WHERE u.loginManager IS NOT NULL AND u.loginManager <> '' AND u.isActive = true"
        )->getResult();

        $checked     = 0;
        $deactivated = 0;
        $unknown     = 0;
        $unchecked   = array();

        foreach ($users as $user) {
            $providerName = (string) $user->getLoginManager();
            $provider     = $registry->get($providerName);

            if (!$provider instanceof UserExistenceCheck) {
                /*
                 * Two cases, one outcome: the provider is not registered (for instance because a
                 * project renamed it), or it cannot answer the question. Both are REPORTED and
                 * not silently passed over — a reconciliation that leaves out accounts without
                 * saying so is worse than none.
                 */
                $unchecked[$providerName] = ($unchecked[$providerName] ?? 0) + 1;
                continue;
            }

            $checked++;

            $known = $provider->knowsIdentifier((string) $user->getExternalId());

            if ($known === null) {
                // "Don't know right now" — then nothing is touched.
                $unknown++;
                continue;
            }

            if ($known === true) {
                continue;
            }

            $deactivated++;

            if (!$dryRun) {
                $user->setIsActive(false);
            }
        }

        if (!$dryRun) {
            $em->flush();
        }

        $output->writeln(sprintf(
            '%s %d of %d provisioned users are no longer known to their external system%s.',
            $dryRun ? '→' : '✓',
            $deactivated,
            $checked,
            $dryRun ? ' (--dry-run, nobody deactivated)' : ' and were deactivated'
        ));

        if ($unknown > 0) {
            $output->writeln(sprintf(
                '<comment>%d users skipped: the external system could not give an answer. '
                .'An outage deactivates nobody.</comment>',
                $unknown
            ));
        }

        foreach ($unchecked as $name => $count) {
            $output->writeln(sprintf(
                '<comment>%d users of "%s" skipped: no registered provider, or it cannot '
                .'check whether users still exist.</comment>',
                $count,
                $name
            ));
        }

        return 0;
    }
}
