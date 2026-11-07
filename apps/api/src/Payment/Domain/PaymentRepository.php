<?php

declare(strict_types=1);

namespace App\Payment\Domain;

interface PaymentRepository
{
    /**
     * @throws PaymentInProgress a concurrent start wrote a payment with the same key or a pending payment of the
     *                           same order first
     */
    public function add(Payment $payment): void;

    public function save(Payment $payment): void;

    public function findByIdempotencyKey(IdempotencyKey $key): ?Payment;

    /**
     * The pending payment of the order (at most one), read under the lock of its row, or null.
     */
    public function lockPendingOfOrder(OrderId $orderId): ?Payment;

    /**
     * The payment of a session of its provider, read under the lock of its row, or null.
     */
    public function lockByProviderPayment(ProviderCode $provider, ProviderPaymentId $providerPaymentId): ?Payment;

    /**
     * Keeps a webhook event of a provider as handled for the payment (forever).
     *
     * @return bool false: the event was handled before (a repeated delivery)
     */
    public function recordWebhookEvent(ProviderCode $provider, ProviderEventId $eventId, PaymentId $paymentId, \DateTimeImmutable $receivedAt): bool;

    /**
     * The newest payment of the order, without a lock, or null.
     */
    public function latestOfOrder(OrderId $orderId): ?Payment;
}
