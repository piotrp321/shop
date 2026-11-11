<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Domain\IdempotencyKey;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\PaymentInProgress;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\PaymentStatus;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderEventId;
use App\Payment\Domain\ProviderPaymentId;

/**
 * Payments in memory, with the two unique indexes of payment_payment (the Idempotency-Key, one pending payment per
 * order), the handled webhook events and a journal of the locks and writes. Stores clones, so a change counts only
 * once saved.
 */
final class InMemoryPayments implements PaymentRepository
{
    /** @var array<string, Payment> by id, in the order they were added */
    private array $payments = [];
    /** @var list<string> "lock <order>", "lock session <provider> <session>", "event <provider> <event>", "add <payment>", "save <payment> <status>" */
    public array $journal = [];
    /** @var array<string, string> "<provider> <event id>" => payment id */
    public array $webhookEvents = [];
    /** What a concurrent transaction commits while lockPendingOfOrder waits for the lock (e.g. a webhook). */
    public ?\Closure $whileWaitingForTheLock = null;
    /** What a concurrent transaction commits right after latestOfOrder read (e.g. a webhook). */
    public ?\Closure $afterLatestRead = null;

    public function add(Payment $payment): void
    {
        foreach ($this->payments as $stored) {
            $sameKey = $stored->idempotencyKey()->toString() === $payment->idempotencyKey()->toString();
            $secondPending = PaymentStatus::Pending === $stored->status() && PaymentStatus::Pending === $payment->status() && $stored->orderId()->equals($payment->orderId());
            if ($sameKey || $secondPending) {
                throw PaymentInProgress::concurrentStart();
            }
        }
        $this->journal[] = 'add '.$payment->id()->toString();
        $this->payments[$payment->id()->toString()] = clone $payment;
    }

    public function save(Payment $payment): void
    {
        $this->journal[] = 'save '.$payment->id()->toString().' '.$payment->status()->value;
        $this->payments[$payment->id()->toString()] = clone $payment;
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?Payment
    {
        foreach ($this->payments as $payment) {
            if ($payment->idempotencyKey()->toString() === $key->toString()) {
                return clone $payment;
            }
        }

        return null;
    }

    public function lockPendingOfOrder(OrderId $orderId): ?Payment
    {
        $this->journal[] = 'lock '.$orderId->toString();
        if (null !== $this->whileWaitingForTheLock) {
            ($this->whileWaitingForTheLock)();
        }
        foreach ($this->payments as $payment) {
            if (PaymentStatus::Pending === $payment->status() && $payment->orderId()->equals($orderId)) {
                return clone $payment;
            }
        }

        return null;
    }

    public function lockByProviderPayment(ProviderCode $provider, ProviderPaymentId $providerPaymentId): ?Payment
    {
        $this->journal[] = 'lock session '.$provider->value.' '.$providerPaymentId->toString();
        foreach ($this->payments as $payment) {
            $session = $payment->providerPaymentId();
            if ($payment->provider() === $provider && null !== $session && $session->equals($providerPaymentId)) {
                return clone $payment;
            }
        }

        return null;
    }

    public function recordWebhookEvent(ProviderCode $provider, ProviderEventId $eventId, PaymentId $paymentId, \DateTimeImmutable $receivedAt): bool
    {
        $key = $provider->value.' '.$eventId->toString();
        if (\array_key_exists($key, $this->webhookEvents)) {
            return false;
        }
        $this->journal[] = 'event '.$key;
        $this->webhookEvents[$key] = $paymentId->toString();

        return true;
    }

    public function latestOfOrder(OrderId $orderId): ?Payment
    {
        $latest = null;
        foreach ($this->payments as $payment) {
            if ($payment->orderId()->equals($orderId) && (null === $latest || $payment->createdAt() >= $latest->createdAt())) {
                $latest = $payment;
            }
        }

        $read = null === $latest ? null : clone $latest;
        if (null !== $this->afterLatestRead) {
            ($this->afterLatestRead)();
        }

        return $read;
    }

    public function get(string $id): Payment
    {
        return clone ($this->payments[$id] ?? throw new \LogicException('No payment '.$id));
    }

    /**
     * @return list<Payment>
     */
    public function all(): array
    {
        return array_values(array_map(static fn (Payment $p): Payment => clone $p, $this->payments));
    }
}
