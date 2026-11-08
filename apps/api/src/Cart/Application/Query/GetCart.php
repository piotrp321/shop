<?php

declare(strict_types=1);

namespace App\Cart\Application\Query;

use App\Cart\Application\CartRef;

/**
 * The cart priced by Pricing's quote; `discountCode` is the code of the preview in the checkout, as typed (blank: no
 * code, at most 64 characters).
 */
final readonly class GetCart
{
    /** The longest code text Pricing's quote accepts. */
    public const int MAX_DISCOUNT_CODE_LENGTH = 64;

    public function __construct(
        public CartRef $cart,
        public ?string $discountCode = null,
    ) {
    }
}
