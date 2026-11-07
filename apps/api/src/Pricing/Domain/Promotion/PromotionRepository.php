<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Pricing\Domain\Price\VariantId;

interface PromotionRepository
{
    public function find(PromotionId $id): ?Promotion;

    /**
     * Writes a new promotion (version 1) or a revised one (written at its version, checked against the stored one),
     * with its variants.
     *
     * @throws PromotionModifiedConcurrently when the stored version is not the one the promotion was read with
     */
    public function save(Promotion $promotion): void;

    public function delete(Promotion $promotion): void;

    /**
     * The variants of the promotion that are in another stored promotion whose period overlaps its period (Q4).
     * Reliable only under the lock of the promotion writes.
     *
     * @return list<VariantId> ordered by id
     */
    public function conflictingVariantIds(Promotion $promotion): array;

    /**
     * Whether the variant is in a promotion active at the moment (start ≤ moment < end).
     */
    public function isInActivePromotion(VariantId $variantId, \DateTimeImmutable $at): bool;
}
