<?php

declare(strict_types=1);

namespace App\Pricing\Application\Demo;

use App\Pricing\Application\Port\CatalogVariant;
use App\Pricing\Application\Port\VariantsForPricing;

/**
 * Every variant of Catalog, read in batches by id, so the catalog is never held in memory.
 */
final class CatalogVariantPages
{
    private const int BATCH_SIZE = 500;

    /**
     * @return iterable<CatalogVariant> in id order
     */
    public static function of(VariantsForPricing $variants): iterable
    {
        $after = null;
        do {
            $batch = $variants->page($after, self::BATCH_SIZE);
            yield from $batch;
            $after = [] === $batch ? null : $batch[\count($batch) - 1]->id;
        } while (self::BATCH_SIZE === \count($batch));
    }
}
