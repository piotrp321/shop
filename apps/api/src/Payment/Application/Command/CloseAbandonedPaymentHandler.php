<?php

declare(strict_types=1);

namespace App\Payment\Application\Command;

use App\Payment\Application\PaymentProviders;
use App\Payment\Application\PaymentProviderUnavailable;
use App\Payment\Application\PendingPaymentClosing;
use App\Payment\Application\Port\Clock;
use App\Payment\Application\SessionCancellation;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\PaymentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Closes an abandoned pending payment in the transaction of command.bus (docs/features/async-reliability/spec.md, D5,
 * W6). The cancellation of a session at the provider is not undone by any rollback, so it comes last, after every check
 * that may refuse the closing and under the lock of the row of the payment:
 *
 * 1. the pending payment of the order, under the lock of its row; none, or another one than the chosen payment (a
 *    webhook ended it, or a new start replaced it, between the choice and the lock) → nothing, the provider is not
 *    called;
 * 2. its session is cancelled at its provider (PendingPaymentClosing): cancelled there → cancelled; rejected there →
 *    failed; paid there → it stays pending with a warning, its webhook settles it (a late webhook of success settles
 *    also a cancelled or failed payment, with a refund when the order is cancelled);
 * 3. the payment is saved.
 *
 * Ordering decided before the lock that the order can no longer be paid, which stays true (AbandonedPayments). The call
 * of the provider runs in the transaction (5 s): its failure rolls everything back and the payment stays pending.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class CloseAbandonedPaymentHandler
{
    private PendingPaymentClosing $closing;

    public function __construct(
        private PaymentRepository $payments,
        PaymentProviders $providers,
        Clock $clock,
        private LoggerInterface $logger,
    ) {
        $this->closing = new PendingPaymentClosing($providers, $clock);
    }

    /**
     * @throws PaymentProviderUnavailable
     */
    public function __invoke(CloseAbandonedPayment $command): AbandonedPaymentOutcome
    {
        $pending = $this->payments->lockPendingOfOrder(OrderId::fromString($command->orderId));
        if (null === $pending || $pending->id()->toString() !== $command->paymentId) {
            return AbandonedPaymentOutcome::NoLongerPending;
        }

        $cancellation = $this->closing->close($pending);
        if (SessionCancellation::AlreadyPaid === $cancellation) {
            $this->logger->warning('An abandoned payment is paid at its provider; it stays pending for its webhook.', [
                'paymentId' => $command->paymentId,
                'orderId' => $command->orderId,
                'provider' => $pending->provider()->value,
            ]);

            return AbandonedPaymentOutcome::PaidAtProvider;
        }
        $this->payments->save($pending);

        return SessionCancellation::AlreadyRejected === $cancellation ? AbandonedPaymentOutcome::Failed : AbandonedPaymentOutcome::Cancelled;
    }
}
