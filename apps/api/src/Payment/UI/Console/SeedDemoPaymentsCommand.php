<?php

declare(strict_types=1);

namespace App\Payment\UI\Console;

use App\Payment\Application\Port\PaymentReset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes all payments and handled webhook events, so the demo data starts without payments (run with the seeds of
 * the simulator and of the orders, which remove the sessions and the orders they belong to).
 * Exit codes: 0 the payments were removed, 1 the guard refused (nothing was changed); a database failure ends the
 * command with the exception.
 */
#[AsCommand(name: 'payment:seed-demo', description: 'Removes all payments of the demo data.')]
final readonly class SeedDemoPaymentsCommand
{
    public function __construct(
        private PaymentReset $reset,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Allow the reset in prod, where it deletes all payments')]
        bool $forceReset = false,
    ): int {
        if ('prod' === $this->environment && !$forceReset) {
            $io->error('The seed deletes all payments. In prod it needs --force-reset; nothing was changed.');

            return Command::FAILURE;
        }

        $this->reset->reset();
        $io->success('payments removed');

        return Command::SUCCESS;
    }
}
