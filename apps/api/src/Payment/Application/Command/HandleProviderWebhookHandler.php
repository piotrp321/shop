<?php

declare(strict_types=1);

namespace App\Payment\Application\Command;

use App\Payment\Application\OrderBusy;
use App\Payment\Application\PaymentNotFound;
use App\Payment\Application\Port\Clock;
use App\Payment\Application\Port\OrderPayments;
use App\Payment\Application\WebhookOutcome;
use App\Payment\Domain\PaymentAmountMismatch;
use App\Payment\Domain\PaymentRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles a verified webhook event in the one transaction of command.bus (spec: "Webhook (HandleProviderWebhook)"):
 *
 * 1. the payment of the session at the provider, under the lock of its row (concurrent deliveries wait); unknown: 404;
 * 2. the deduplication: an event handled before changes nothing; otherwise it is recorded;
 * 3. the payment succeeds (with the amount checked: another one is a 422, logged) or fails;
 * 4. a payment that succeeded just now pays its order through Ordering (MarkOrderPaid, nested in this transaction);
 *    when the money cannot pay it (the order cancelled, the payment executed after the deadline, the order paid by
 *    another payment) the payment stays succeeded, is marked for a refund by hand and the error is logged;
 * 5. the payment is saved.
 *
 * Every failure passes on and rolls everything back, the recorded event too, so the retry of the provider is handled
 * anew: OrderBusy (a version conflict of the order, 503) and any other failure of Ordering (500).
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class HandleProviderWebhookHandler
{
    public function __construct(
        private PaymentRepository $payments,
        private OrderPayments $orders,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws PaymentNotFound
     * @throws PaymentAmountMismatch
     * @throws OrderBusy
     */
    public function __invoke(HandleProviderWebhook $command): void
    {
        $event = $command->event;
        $payment = $this->payments->lockByProviderPayment($command->provider, $event->providerPaymentId) ?? throw new PaymentNotFound();
        $now = $this->clock->now();
        if (!$this->payments->recordWebhookEvent($command->provider, $event->eventId, $payment->id(), $now)) {
            return;
        }

        if (WebhookOutcome::Failed === $event->outcome) {
            $payment->fail($now);
            $this->payments->save($payment);

            return;
        }

        $executedAt = $event->executedAt ?? throw new \LogicException('A succeeded payment has the moment of its execution.');
        try {
            $succeeded = $payment->succeed($event->amount, $executedAt, $now);
        } catch (PaymentAmountMismatch $mismatch) {
            $this->logger->error('Payment webhook: the provider reports another amount than the payment; refused.', [
                'provider' => $command->provider->value,
                'eventId' => $event->eventId->toString(),
                'paymentId' => $payment->id()->toString(),
                'amount' => $event->amount->minorUnits(),
                'expected' => $payment->amount()->minorUnits(),
            ]);

            throw $mismatch;
        }

        if ($succeeded) {
            $refund = $this->orders->markPaid($payment->orderId(), $payment->id(), $payment->amount(), $executedAt)->refundReason();
            if (null !== $refund) {
                $payment->requireRefund($refund);
                $this->logger->error('Payment webhook: the payment cannot pay its order and requires a manual refund.', [
                    'provider' => $command->provider->value,
                    'paymentId' => $payment->id()->toString(),
                    'orderId' => $payment->orderId()->toString(),
                    'reason' => $refund->value,
                ]);
            }
        }
        $this->payments->save($payment);
    }
}
