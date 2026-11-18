<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

/**
 * A line an order draws: candidate products (global indexes, popular ones likelier), the draws that choose one of them
 * by the season of its department and one of its variants, the quantity and the id of the line.
 */
final readonly class LineDraft
{
    /**
     * @param list<int> $candidates
     */
    public function __construct(
        public array $candidates,
        public float $seasonDraw,
        public float $variantDraw,
        public int $quantity,
        public string $id,
    ) {
    }
}
