<?php

declare(strict_types=1);

namespace App\Catalog\Application\Search;

/**
 * The offer of a variant for the search index: its gross price in grosze (null without one) and whether one unit can
 * be bought.
 */
final readonly class VariantFacts
{
    public function __construct(
        public ?int $gross,
        public bool $purchasable,
    ) {
    }
}
