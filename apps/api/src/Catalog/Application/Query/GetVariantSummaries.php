<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

/**
 * The product name, label and main image (in the `listing` size) of 1 to 100 variants, read from the Catalog tables
 * (never from the search index), e.g. for the lines of a cart. The variants of a draft are left out like variants
 * that do not exist (decision A3), so the store can neither show nor sell them.
 */
final readonly class GetVariantSummaries
{
    public const int MAX_VARIANTS = 100;

    /**
     * @param list<string> $variantIds variant UUIDs
     */
    public function __construct(public array $variantIds)
    {
    }
}
