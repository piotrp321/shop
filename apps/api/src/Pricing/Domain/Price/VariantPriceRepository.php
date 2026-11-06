<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Price;

interface VariantPriceRepository
{
    public function find(VariantId $id): ?VariantPrice;

    /**
     * The stored prices of the variants, in one read straight from the store (no cache, no session state), in no
     * particular order. A variant without a price is absent from the result.
     *
     * @param list<VariantId> $ids
     *
     * @return list<VariantPrice>
     */
    public function findMany(array $ids): array;

    /**
     * All stored prices in batches by variant id (keyset), read like findMany(): the stored state, nothing kept.
     *
     * @param int $limit 1 to 1000
     *
     * @return list<VariantPrice> ordered by variant id, starting after $after; empty after the last price
     */
    public function page(?VariantId $after, int $limit): array;

    /**
     * Writes the price and appends its unsaved history entries in one transaction, which also publishes its
     * VariantPriceChanged. A price without unsaved entries is unchanged and nothing is written. History is never
     * changed or deleted.
     *
     * After an exception nothing is stored, but the price is spent: its history entries were already released and
     * its version counts the unsaved change. The persistence session may be unusable too (Doctrine closes the
     * entity manager). A retry reads the price again in a new request or message, with a fresh session.
     *
     * @throws PriceModifiedConcurrently when the stored version is not the one the price was read with, or a price
     *                                   created concurrently was stored first
     */
    public function save(VariantPrice $price): void;
}
