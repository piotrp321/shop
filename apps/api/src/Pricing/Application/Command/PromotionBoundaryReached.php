<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

/**
 * A boundary of the period of a promotion (its start or its end) has come: the prices of its variants start or stop
 * being in force. Sent with a delay until the boundary (transport `promotions`) by the write of the promotion, and
 * again by its handler when it came too early. The moment tells which boundary it is: once the period changed, the
 * message of the old boundary has no effect.
 */
final readonly class PromotionBoundaryReached
{
    public function __construct(
        public string $promotionId,
        public \DateTimeImmutable $at,
    ) {
    }
}
