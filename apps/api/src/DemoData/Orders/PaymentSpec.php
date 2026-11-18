<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

/**
 * A row of payment_payment: the succeeded payment of a paid order, of the gross total of the order. The idempotency key
 * is random (never from the seed).
 */
final readonly class PaymentSpec
{
    public function __construct(
        public string $id,
        public string $provider,
        public int $amount,
        public string $idempotencyKey,
        public string $requestFingerprint,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $executedAt,
    ) {
    }
}
