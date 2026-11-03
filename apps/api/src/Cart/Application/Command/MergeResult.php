<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\View\CartView;

/**
 * The cart of the customer after a merge, with how the merge ended (`merged`, `already_merged`, `nothing_to_merge`)
 * and whether a sum was cut or a guest line skipped (`truncated`).
 */
final readonly class MergeResult
{
    public function __construct(
        public CartView $cart,
        public string $result,
        public bool $truncated,
    ) {
    }
}
