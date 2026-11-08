<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Basket;

use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\Money\Money;

/**
 * The code a quote applied and the discount it gave: always at least one grosz (Q7).
 */
final readonly class AppliedDiscount
{
    public function __construct(
        private DiscountCodeId $codeId,
        private DiscountCodeValue $code,
        private DiscountValue $value,
        private Money $amount,
    ) {
    }

    public function codeId(): DiscountCodeId
    {
        return $this->codeId;
    }

    public function code(): DiscountCodeValue
    {
        return $this->code;
    }

    public function value(): DiscountValue
    {
        return $this->value;
    }

    public function amount(): Money
    {
        return $this->amount;
    }
}
