<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

interface ProductPriceRepository
{
    public function find(ProductId $id): ?ProductPrice;

    /**
     * The stored prices of the products, in one read straight from the store (no cache, no session state), in no
     * particular order. A product without a price is absent from the result.
     *
     * @param list<ProductId> $ids
     *
     * @return list<ProductPrice>
     */
    public function findMany(array $ids): array;

    /**
     * All stored prices in batches by product id (keyset), read like findMany(): the stored state, nothing kept.
     *
     * @param int $limit 1 to 1000
     *
     * @return list<ProductPrice> ordered by product id, starting after $after; empty after the last price
     */
    public function page(?ProductId $after, int $limit): array;

    /**
     * Writes the price and appends its unsaved history entries in one transaction. A price without unsaved entries
     * is unchanged and nothing is written. History is never changed or deleted.
     *
     * After an exception nothing is stored, but the price is spent: its history entries were already released and
     * its version counts the unsaved change. The persistence session may be unusable too (Doctrine closes the
     * entity manager). A retry reads the price again in a new request or message, with a fresh session.
     *
     * @throws PriceModifiedConcurrently when the stored version is not the one the price was read with, or a price
     *                                   created concurrently was stored first
     */
    public function save(ProductPrice $price): void;
}
