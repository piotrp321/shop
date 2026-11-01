<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

interface ProductPriceRepository
{
    public function find(ProductId $id): ?ProductPrice;

    /**
     * Writes the price and appends its unsaved history entries in one transaction. A price without unsaved entries
     * is unchanged and nothing is written. History is never changed or deleted.
     *
     * @throws PriceModifiedConcurrently when the stored version is not the one the price was read with, or a price
     *                                   created concurrently was stored first
     */
    public function save(ProductPrice $price): void;
}
