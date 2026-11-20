<?php

declare(strict_types=1);

namespace App\Payment\UI\Console;

use App\Payment\Application\AbandonedPayments;
use App\Payment\Application\Command\AbandonedPaymentOutcome;
use App\Payment\Application\Command\CloseAbandonedPayment;
use App\Payment\Application\Port\PendingPayment;
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
 * Closes the pending payments of orders Ordering no longer lets pay (docs/features/async-reliability/spec.md, D5, W6):
 * the session is cancelled at its provider as a new attempt cancels it, a session paid at the provider is left for its
 * webhook. The CronJob order-expiry of the chart runs it after ordering:expire-overdue; locally it is run by hand. The
 * pending payments are read in batches by the keyset of the order id with one question to Ordering per batch, and each
 * payment is its own CloseAbandonedPayment on command.bus, so its own transaction, with the state of the ORM forgotten
 * before it. Idempotent: a closed payment is no longer pending.
 *
 * The failure of one payment (e.g. its provider is unavailable) is logged as an error with the ids of the payment and
 * of its order and the exception (so it reaches Sentry), and the others go on.
 *
 * Exit codes: 0 done (also when nothing was abandoned), 1 the batch size is not an integer from 1 to 100 (nothing was
 * changed), 2 at least one payment could not be closed, or the run stopped on an error (e.g. the database is
 * unavailable; the command prints a message and hands the error on to the console, which ends with 2). The payments
 * closed until then stay closed.
 */
#[AsCommand(name: 'payment:close-abandoned', description: 'Closes the pending payments of orders that can no longer be paid (cancels their sessions at the providers).')]
final readonly class CloseAbandonedPaymentsCommand
{
    public const int ERROR = 2;
    public const int DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private AbandonedPayments $abandoned,
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
        private ManagerRegistry $doctrine,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Pending payments read per batch, 1 to 100 (each payment is closed in its own transaction)')]
        string $batchSize = (string) self::DEFAULT_BATCH_SIZE,
    ): int {
        if (1 !== preg_match('/^[1-9][0-9]?$|^100$/D', $batchSize)) {
            $io->error('The batch size must be an integer from 1 to 100. Nothing was changed.');

            return Command::FAILURE;
        }

        $found = 0;
        $failed = 0;
        $outcomes = array_fill_keys(array_map(static fn (AbandonedPaymentOutcome $outcome): string => $outcome->name, AbandonedPaymentOutcome::cases()), 0);
        try {
            foreach ($this->abandoned->batches((int) $batchSize) as $payments) {
                foreach ($payments as $payment) {
                    ++$found;
                    try {
                        ++$outcomes[$this->close($payment)->name];
                    } catch (\Throwable $failure) {
                        ++$failed;
                        $this->logger->error('payment:close-abandoned: the payment could not be closed.', [
                            'paymentId' => $payment->paymentId->toString(),
                            'orderId' => $payment->orderId->toString(),
                            'exception' => $failure,
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            $io->error(\sprintf('The closing stopped: %s The payments closed so far stay closed; run the command again.', rtrim($e->getMessage(), '.').'.'));

            // Handed on, not swallowed: the console reports it (a log with the exception, an event of Sentry) and ends with this code.
            throw new \RuntimeException($e->getMessage(), self::ERROR, $e);
        }

        if (0 === $found) {
            $io->success('No abandoned payments.');

            return Command::SUCCESS;
        }
        $io->writeln('cancelled: '.$outcomes[AbandonedPaymentOutcome::Cancelled->name]);
        $io->writeln('failed: '.$outcomes[AbandonedPaymentOutcome::Failed->name]);
        $io->writeln('paid at the provider, left for the webhook: '.$outcomes[AbandonedPaymentOutcome::PaidAtProvider->name]);
        $io->writeln('no longer pending: '.$outcomes[AbandonedPaymentOutcome::NoLongerPending->name]);
        $closed = $outcomes[AbandonedPaymentOutcome::Cancelled->name] + $outcomes[AbandonedPaymentOutcome::Failed->name];
        if ($failed > 0) {
            $io->error(\sprintf('Closed %d of %d abandoned payments; %d could not be closed (see the log).', $closed, $found, $failed));

            return self::ERROR;
        }
        $io->success(\sprintf('Closed %d of %d abandoned payments.', $closed, $found));

        return Command::SUCCESS;
    }

    /**
     * @throws \Throwable what the closing threw, unwrapped from the bus
     */
    private function close(PendingPayment $payment): AbandonedPaymentOutcome
    {
        $this->forget();
        try {
            $outcome = $this->commandBus->dispatch(new CloseAbandonedPayment($payment->orderId->toString(), $payment->paymentId->toString()))->last(HandledStamp::class)?->getResult();
        } catch (HandlerFailedException $failed) {
            $wrapped = $failed->getWrappedExceptions();

            throw 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failed;
        }
        \assert($outcome instanceof AbandonedPaymentOutcome);

        return $outcome;
    }

    /**
     * Forgets what the ORM read for the previous payment, or replaces the entity manager when a failed write closed it.
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
}
