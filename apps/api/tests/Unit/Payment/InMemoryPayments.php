<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payment;

use App\Payment\Domain\IdempotencyKey;
use App\Payment\Domain\OrderId;
use App\Payment\Domain\Payment;
use App\Payment\Domain\PaymentInProgress;
use App\Payment\Domain\PaymentRepository;
use App\Payment\Domain\PaymentStatus;

/**
 * Payments in memory, with the two unique indexes of payment_payment (the Idempotency-Key, one pending payment per
 * order) and a journal of the locks and writes. Stores clones, so a change counts only once saved.
 */
final class InMemoryPayments implements PaymentRepository
{
    /** @var array<string, Payment> by id, in the order they were added */
    private array $payments = [];
    /** @var list<string> "lock <order>", "add <payment>", "save <payment> <status>" */
    public array $journal = [];

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
        foreach ($this->payments as $payment) {
            if (PaymentStatus::Pending === $payment->status() && $payment->orderId()->equals($orderId)) {
                return clone $payment;
            }
        }

        return null;
    }

    public function latestOfOrder(OrderId $orderId): ?Payment
    {
        $latest = null;
        foreach ($this->payments as $payment) {
            if ($payment->orderId()->equals($orderId) && (null === $latest || $payment->createdAt() >= $latest->createdAt())) {
                $latest = $payment;
            }
        }

        return null === $latest ? null : clone $latest;
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
