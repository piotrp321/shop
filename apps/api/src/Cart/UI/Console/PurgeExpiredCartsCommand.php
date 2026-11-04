<?php

declare(strict_types=1);

namespace App\Cart\UI\Console;

use App\Cart\Application\Command\PurgeExpiredCarts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Removes the carts past their expiry time (guests in any status and customers) with their lines, in batches: each
 * batch is its own PurgeExpiredCarts on command.bus, so its own transaction, repeated until a batch removes nothing.
 * Run deliberately (by hand or a scheduler), never by make init, make up or make seed.
 *
 * Exit codes: 0 the purge is done (also when nothing was expired), 1 the batch size is not a positive integer
 * (nothing was changed), 2 the run stopped on an error (e.g. the database is unavailable); the batches removed until
 * then stay removed.
 */
#[AsCommand(name: 'cart:purge-expired', description: 'Removes the expired carts with their lines, in batches.')]
final readonly class PurgeExpiredCartsCommand
{
    public const int ERROR = 2;

    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Carts removed per batch (each batch is one transaction)')]
        string $batchSize = (string) PurgeExpiredCarts::DEFAULT_BATCH_SIZE,
    ): int {
        if (1 !== preg_match('/^[1-9][0-9]{0,8}$/', $batchSize)) {
            $io->error('The batch size must be a positive integer. Nothing was changed.');

            return Command::FAILURE;
        }

        $batches = 0;
        $total = 0;
        try {
            while (0 < $removed = $this->removeBatch((int) $batchSize)) {
                ++$batches;
                $total += $removed;
                $io->writeln(\sprintf('Batch %d: %d %s removed.', $batches, $removed, 1 === $removed ? 'cart' : 'carts'));
            }
        } catch (\Throwable $e) {
            $io->error(\sprintf('The purge stopped: %s The carts removed so far (%d) stay removed; run the command again.', rtrim($e->getMessage(), '.').'.', $total));

            return self::ERROR;
        }

        if (0 === $total) {
            $io->success('No expired carts.');

            return Command::SUCCESS;
        }
        $io->success(\sprintf('Removed %d expired %s in %d %s.', $total, 1 === $total ? 'cart' : 'carts', $batches, 1 === $batches ? 'batch' : 'batches'));

        return Command::SUCCESS;
    }

    private function removeBatch(int $batchSize): int
    {
        $removed = $this->commandBus->dispatch(new PurgeExpiredCarts($batchSize))->last(HandledStamp::class)?->getResult();
        \assert(\is_int($removed));

        return $removed;
    }
}
