<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Basket;

use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PromotionId;

/**
 * The promotion that gives the effective price of a line, as the quote copies it.
 */
final readonly class BasketPromotion
{
    public function __construct(
        private PromotionId $id,
        private string $name,
        private PercentOff $percentOff,
    ) {
    }

    public function id(): PromotionId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function percentOff(): PercentOff
    {
        return $this->percentOff;
    }
}
