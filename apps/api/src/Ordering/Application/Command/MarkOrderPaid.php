<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

/**
 * A payment of the order succeeded at the provider: Payment dispatches it nested in the transaction of the webhook,
 * so the payment and the status of the order change together. The amount (in grosze) and the currency are the ones
 * paid; executedAt is the moment the provider executed the payment.
 */
final readonly class MarkOrderPaid
{
    public function __construct(
        public string $orderId,
        public string $paymentId,
        public int $amount,
        public string $currency,
        public \DateTimeImmutable $executedAt,
    ) {
    }
}
