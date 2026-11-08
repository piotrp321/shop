<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * Why the code of a quote gives no discount: the problem type and the Polish detail as Pricing gives them, and the
 * minimum only for a subtotal below it.
 */
final readonly class QuotedCodeRejection
{
    public function __construct(
        public string $type,
        public string $detail,
        public ?int $minimumOrderGross,
    ) {
    }
}
