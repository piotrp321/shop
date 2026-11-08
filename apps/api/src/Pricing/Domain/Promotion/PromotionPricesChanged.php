<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Shared\Domain\Event\DomainEvent;

/**
 * The effective prices of a product changed with a promotion: a boundary of its period was reached (its prices start
 * or stop being in force), or the end of an active promotion moved (the end the store shows changed). One event per
 * product of the promotion, however many of its variants the promotion has, written to the outbox in one transaction.
 */
final readonly class PromotionPricesChanged implements DomainEvent
{
    public function __construct(
        public string $productId,
        public PromotionPriceChangeSource $source,
        public \DateTimeImmutable $occurredAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
