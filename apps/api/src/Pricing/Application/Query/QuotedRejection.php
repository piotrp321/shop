<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

/**
 * Why a code gives no discount, with the problem type and the Polish detail of DiscountCodeRejection (the same texts
 * as the 409 of an order). `code` is the text as the quote read it: trimmed, in upper case (ASCII letters only), with
 * bytes that are not UTF-8 replaced. `minimumOrderGross` only for `below_minimum`.
 */
final readonly class QuotedRejection
{
    public function __construct(
        public string $code,
        public string $reason,
        public string $type,
        public string $detail,
        public ?int $minimumOrderGross,
    ) {
    }
}
