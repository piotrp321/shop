<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

/**
 * A reservation line that asks for more than is available.
 */
final readonly class Shortage
{
    public function __construct(
        public string $variantId,
        public int $requested,
        public int $available,
    ) {
    }
}
