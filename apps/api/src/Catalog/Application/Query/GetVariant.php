<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * The product and the tax class of a variant, or null when the variant does not exist.
 */
final readonly class GetVariant
{
    public function __construct(public string $variantId)
    {
    }
}
