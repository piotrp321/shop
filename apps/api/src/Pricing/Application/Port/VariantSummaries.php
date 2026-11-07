<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Pricing\Domain\Price\VariantId;

/**
 * The variants as Catalog knows them (Catalog GetVariantSummaries, asked in batches of its limit). A variant that
 * does not exist, or belongs to a draft, is absent.
 */
interface VariantSummaries
{
    /**
     * @param list<VariantId> $variantIds
     *
     * @return array<string, VariantSummary> by variant id, only the variants Catalog knows
     */
    public function of(array $variantIds): array;
}
