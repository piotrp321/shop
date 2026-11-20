<?php

declare(strict_types=1);

namespace App\Ordering\UI\Console;

use App\Ordering\Application\Command\ExpireOverdueOrder;
use App\Ordering\Application\OverdueOrders;
use App\Ordering\Domain\OrderModifiedConcurrently;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Cancels the new orders past their deadline, the window of the webhooks and the grace (docs/features/async-reliability/spec.md,
 * W5): the safety net of the delayed ExpireUnpaidOrder, for an order whose message is in `failed`, was lost or waits
 * behind a worker that stands. The CronJob order-expiry of the chart runs it every five minutes; locally it is run by
 * hand. Each order is its own ExpireOverdueOrder on command.bus, so its own transaction, with the state of the ORM
 * forgotten before it; a version conflict is retried once on the order read anew. Idempotent: a second run finds
 * nothing.
 *
 * The failure of one order (e.g. a reservation Inventory does not know) is logged as an error with the id of the order
 * and the exception (so it reaches Sentry), and the others go on.
 *
 * Exit codes: 0 done (also when nothing was overdue), 1 the batch size is not a positive integer (nothing was changed),
 * 2 at least one order could not be expired, or the run stopped on an error (e.g. the database is unavailable; the
 * command prints a message and hands the error on to the console, which ends with 2). The orders expired until then
 * stay cancelled.
 */
#[AsCommand(name: 'ordering:expire-overdue', description: 'Cancels the unpaid orders past their deadline whose delayed expiry did not come (in batches).')]
final readonly class ExpireOverdueOrdersCommand
{
    public const int ERROR = 2;
    public const int DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private OverdueOrders $overdue,
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
        private ManagerRegistry $doctrine,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Orders read per batch (each order is one transaction)')]
        string $batchSize = (string) self::DEFAULT_BATCH_SIZE,
    ): int {
        if (1 !== preg_match('/^[1-9][0-9]{0,8}$/', $batchSize)) {
            $io->error('The batch size must be a positive integer. Nothing was changed.');

            return Command::FAILURE;
        }

        $found = 0;
        $expired = 0;
        $failed = 0;
        try {
            foreach ($this->overdue->batches((int) $batchSize) as $orderIds) {
                foreach ($orderIds as $orderId) {
                    ++$found;
                    try {
                        if ($this->expire($orderId)) {
                            ++$expired;
                        }
                    } catch (\Throwable $failure) {
                        ++$failed;
                        $this->logger->error('ordering:expire-overdue: the order could not be expired.', ['orderId' => $orderId, 'exception' => $failure]);
                    }
                }
            }
        } catch (\Throwable $e) {
            $io->error(\sprintf('The expiry stopped: %s The orders expired so far (%d) stay cancelled; run the command again.', rtrim($e->getMessage(), '.').'.', $expired));

            // Handed on, not swallowed: the console reports it (a log with the exception, an event of Sentry) and ends with this code.
            throw new \RuntimeException($e->getMessage(), self::ERROR, $e);
        }

        if ($failed > 0) {
            $io->error(\sprintf('Expired %d overdue %s; %d could not be expired (see the log).', $expired, self::orders($expired), $failed));

            return self::ERROR;
        }
        // An order found but not cancelled was paid or cancelled meanwhile (by a payment or by its delayed message).
        $io->success(0 === $found ? 'No overdue orders.' : \sprintf('Expired %d overdue %s.', $expired, self::orders($expired)));

        return Command::SUCCESS;
    }

    /**
     * @throws \Throwable what the expiry threw, unwrapped from the bus
     */
    private function expire(string $orderId): bool
    {
        $this->forget();
        try {
            return $this->dispatch($orderId);
        } catch (OrderModifiedConcurrently) {
            $this->forget();

            return $this->dispatch($orderId);
        }
    }

    private function dispatch(string $orderId): bool
    {
        try {
            $cancelled = $this->commandBus->dispatch(new ExpireOverdueOrder($orderId))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failed) {
            $wrapped = $failed->getWrappedExceptions();

            throw 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failed;
        }
        \assert(\is_bool($cancelled));

        return $cancelled;
    }

    /**
     * Forgets what the ORM read for the previous order, or replaces the entity manager when a failed write closed it.
     */
    private function forget(): void
    {
        $manager = $this->doctrine->getManager();
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->doctrine->resetManager();

            return;
        }
        $manager->clear();
    }

    private static function orders(int $count): string
    {
        return 1 === $count ? 'order' : 'orders';
    }
}
