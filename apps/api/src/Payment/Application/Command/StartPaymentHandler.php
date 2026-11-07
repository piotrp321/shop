<?php

declare(strict_types=1);

namespace App\Payment\Application\Command;

use App\Payment\Application\OrderNotFound;
use App\Payment\Application\PaymentAccess;
use App\Payment\Application\PaymentProviders;
use App\Payment\Application\PaymentProviderUnavailable;
use App\Payment\Application\Port\Clock;
use App\Payment\Application\Port\PayableOrders;
use App\Payment\Application\SessionCancellation;
use App\Payment\Application\View\PaymentView;
use App\Payment\Domain\IdempotencyKey;
use App\Payment\Domain\InvalidPaymentInput;
use App\Payment\Domain\OrderAlreadyPaid;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\OrderToPay;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentDeadlinePassed;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\PaymentIdempotencyKeyReused;
use App\Payment\Domain\PaymentInProgress;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\RequestFingerprint;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Starts a payment in the one transaction of command.bus (spec: "Rozpoczęcie płatności"):
 *
 * 1. the access to the order (Ordering GetOrderForPayment), before the replay, so a key alone never gives a payment;
 * 2. the replay: the payment of the key, when it was started for the same order and provider; another one is a 409;
 * 3. the state of the order: paid, cancelled or past the deadline is a 409;
 * 4. the pending payment of the order, under the lock of its row, is cancelled at its provider first: cancelled
 *    there → cancelled here; rejected there → failed here; paid there → 409, nothing changes (the webhook confirms
 *    it); the provider unavailable → 503, nothing changes;
 * 5. the order is read anew and its state checked again as in step 3: a webhook of the earlier payment that held the
 *    lock of step 4 has committed the order paid meanwhile, and no pending payment is left to stop the start;
 * 6. the new payment is written (the unique indexes refuse a concurrent start before the provider is called), then
 *    the provider opens its session until the deadline of the order and the payment keeps it.
 *
 * The calls of the providers run in the transaction (5 s each): any failure rolls back everything, also the local
 * cancellation of the earlier payment. A session cancelled at its provider stays cancelled, and cancelling it again
 * succeeds, so a retry takes step 4 the same way.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class StartPaymentHandler
{
    private const string RETURN_PATH = '/checkout/confirmation';

    public function __construct(
        private PaymentRepository $payments,
        private PayableOrders $orders,
        private PaymentProviders $providers,
        private Clock $clock,
        #[Autowire(env: 'STORE_URL')]
        private string $storeUrl,
    ) {
    }

    /**
     * @throws InvalidPaymentInput         unknown provider, malformed key
     * @throws OrderNotFound
     * @throws PaymentIdempotencyKeyReused
     * @throws OrderAlreadyPaid
     * @throws PaymentDeadlinePassed
     * @throws PaymentInProgress
     * @throws PaymentProviderUnavailable
     */
    public function __invoke(StartPayment $command): StartedPayment
    {
        $key = IdempotencyKey::fromString($command->idempotencyKey);
        $provider = ProviderCode::fromString($command->provider);
        $order = $this->order($command->orderId, $command->access);
        $fingerprint = RequestFingerprint::of($order->orderId, $provider);

        $earlier = $this->payments->findByIdempotencyKey($key);
        if (null !== $earlier) {
            if (!$earlier->isReplayOf($fingerprint)) {
                throw new PaymentIdempotencyKeyReused();
            }

            return new StartedPayment(PaymentView::of($earlier), true);
        }

        $order->ensurePaymentCanStart();
        $this->endPendingPayment($order->orderId);
        $order = $this->order($command->orderId, $command->access);
        $order->ensurePaymentCanStart();

        $payment = Payment::start(PaymentId::fromString(Uuid::v7()->toRfc4122()), $order->orderId, $provider, $order->amount(), $key, $fingerprint, $this->clock->now());
        $this->payments->add($payment);
        $session = $this->providers->get($provider)->createSession($payment->id(), $payment->amount(), $order->number, rtrim($this->storeUrl, '/').self::RETURN_PATH, $order->paymentDeadline);
        $payment->attachProviderSession($session->providerPaymentId, $session->redirectUrl);
        $this->payments->save($payment);

        return new StartedPayment(PaymentView::of($payment), false);
    }

    private function order(string $orderId, PaymentAccess $access): OrderToPay
    {
        try {
            $id = OrderId::fromString($orderId);
        } catch (\InvalidArgumentException) {
            throw new OrderNotFound();
        }

        return $this->orders->find($id, $access) ?? throw new OrderNotFound();
    }

    /**
     * @throws PaymentInProgress          the provider executed the pending payment
     * @throws PaymentProviderUnavailable
     */
    private function endPendingPayment(OrderId $orderId): void
    {
        $pending = $this->payments->lockPendingOfOrder($orderId);
        if (null === $pending) {
            return;
        }
        $session = $pending->providerPaymentId();
        // A payment gets its session in the transaction that writes it, so a stored pending one has one; without it
        // there is nothing to cancel at the provider.
        $cancellation = null === $session ? SessionCancellation::Cancelled : $this->providers->get($pending->provider())->cancelSession($session);
        match ($cancellation) {
            SessionCancellation::Cancelled => $pending->cancel($this->clock->now()),
            SessionCancellation::AlreadyRejected => $pending->fail($this->clock->now()),
            SessionCancellation::AlreadyPaid => throw PaymentInProgress::alreadyExecuted(),
        };
        $this->payments->save($pending);
    }
}
