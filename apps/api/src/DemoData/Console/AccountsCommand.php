<?php

declare(strict_types=1);

namespace App\DemoData\Console;

use App\DemoData\Accounts\AccountReconciler;
use App\DemoData\Accounts\KeycloakAdminFactory;
use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Profile\RunContextFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Step 4 of the generator: the staff (realm backoffice, with the role of the number) and the customers (realm shop) in
 * Keycloak, disabled and without credentials, through the admin REST API (spec: "Konta w Keycloak"). Every run
 * reconciles each realm with its target set (AccountReconciler): afterwards the members of the group
 * demo-data-generated are exactly the accounts of the profile and the seed, and no account outside the group has changed.
 * `--anchor` is accepted like in every step; the accounts do not depend on it.
 * Exit codes: 0 done, 1 refused (bad option, no password), failed (Keycloak) or conflicts (listed, foreign accounts
 * unchanged).
 */
#[AsCommand(name: 'demo-data:accounts', description: 'Reconciles the generated staff and customer accounts in Keycloak (step 4).')]
final readonly class AccountsCommand
{
    public function __construct(
        private RunContextFactory $contexts,
        private PersonDictionaries $people,
        private KeycloakAdminFactory $keycloak,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput]
        AccountsInput $accounts,
        #[Option(description: 'Profile of profiles.yaml (not --profile, which the console itself reserves)')]
        string $demoProfile = 'small',
        #[Option(description: 'Reference moment (default: the start of the current UTC day)')]
        ?string $anchor = null,
        #[Option(description: 'Namespace of the seed (a UUID) instead of the one of profiles.yaml')]
        ?string $seed = null,
        #[Option(description: 'Accounts per import request')]
        int $batchSize = 2000,
        #[Option(description: 'Import requests at a time')]
        int $streams = 4,
    ): int {
        try {
            if ($batchSize < 1 || $streams < 1) {
                throw new \InvalidArgumentException('--batch-size and --streams have to be at least 1.');
            }
            $realms = $accounts->realms($this->contexts->create($demoProfile, $seed, null, $anchor), $this->people, $this->keycloak);
            $reconciler = new AccountReconciler(max(1, $accounts->pageSize), $batchSize, $streams);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $conflicts = [];
        foreach ($realms as [$realm, $directory, $target]) {
            try {
                $report = $reconciler->reconcile($directory, $target, static function (string $phase, int $total) use ($io, $realm): void {
                    if (0 === $total % 10_000) {
                        $io->writeln(\sprintf('%s: %d %s', $realm, $total, $phase));
                    }
                });
            } catch (\RuntimeException $e) {
                $io->error(\sprintf('%s: %s', $realm, $e->getMessage()));

                return Command::FAILURE;
            }
            $io->writeln(\sprintf(
                '%s: %d accounts of the target, %d kept, %d deleted, %d imported, %d conflicts%s',
                $realm,
                $target->count,
                $report->kept,
                $report->deleted,
                $report->imported,
                \count($report->conflicts),
                $report->groupCreated ? ' (group '.AccountReconciler::GROUP.' created)' : '',
            ));
            if ($report->notFound > 0) {
                $io->writeln(\sprintf('%s: %d members to delete no longer existed', $realm, $report->notFound));
            }
            if ([] !== $report->notMembers) {
                $io->warning(\sprintf('%s: %d accounts left the group %s before their deletion and were kept: %s', $realm, \count($report->notMembers), AccountReconciler::GROUP, implode(', ', $report->notMembers)));
            }
            foreach ($report->conflicts as $conflict) {
                $conflicts[] = $realm.': '.$conflict;
            }
        }

        if ([] !== $conflicts) {
            $io->error(\sprintf('%d conflict(s); no account outside the group %s was changed:', \count($conflicts), AccountReconciler::GROUP));
            $io->listing($conflicts);

            return Command::FAILURE;
        }
        $io->success('the generated accounts are reconciled');

        return Command::SUCCESS;
    }
}
