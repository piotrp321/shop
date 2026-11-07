<?php

declare(strict_types=1);

namespace App\PaymentSimulator\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes the transactions of the payment simulator and the queue of its webhook deliveries (`payment_simulator`), so
 * the demo data starts without sessions of fake providers. Both in one transaction. The queues `outbox`, `scheduled`,
 * `events` and `failed` stay.
 * Exit codes: 0 the data was removed, 1 the guard refused (nothing was changed); a database failure rolls back and
 * ends the command with the exception.
 */
#[AsCommand(name: 'payment-simulator:seed-demo', description: 'Removes the transactions and queued webhook deliveries of the payment simulator.')]
final readonly class SeedDemoCommand
{
    public function __construct(
        private Connection $connection,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Allow the reset in prod, where it deletes all transactions of the simulator')]
        bool $forceReset = false,
    ): int {
        if ('prod' === $this->environment && !$forceReset) {
            $io->error('The seed deletes all transactions of the payment simulator. In prod it needs --force-reset; nothing was changed.');

            return Command::FAILURE;
        }

        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('TRUNCATE payment_simulator_transaction');
            $this->connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'payment_simulator'");
            $this->connection->commit();
        } catch (\Throwable $failure) {
            $this->connection->rollBack();

            throw $failure;
        }
        $io->success('payment simulator transactions removed');

        return Command::SUCCESS;
    }
}
