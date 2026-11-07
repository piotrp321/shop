<?php

declare(strict_types=1);

namespace App\Ordering\UI\Console;

use App\Ordering\Application\Port\OrderingReset;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes all orders, their lines and the queue of their ExpireUnpaidOrder messages (`scheduled`), so the demo data
 * starts without orders (run before inventory:seed-demo, which removes their reservations). The number sequence
 * stays: gaps are allowed. The queues `outbox` and `failed` stay.
 * Exit codes: 0 the orders were removed, 1 the guard refused (nothing was changed).
 */
#[AsCommand(name: 'ordering:seed-demo', description: 'Removes all orders of the demo data.')]
final readonly class SeedDemoOrdersCommand
{
    public function __construct(
        private OrderingReset $reset,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Allow the reset in prod, where it deletes all orders')]
        bool $forceReset = false,
    ): int {
        if ('prod' === $this->environment && !$forceReset) {
            $io->error('The seed deletes all orders. In prod it needs --force-reset; nothing was changed.');

            return Command::FAILURE;
        }

        $this->reset->reset();
        $io->success('orders removed');

        return Command::SUCCESS;
    }
}
