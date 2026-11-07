<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionActor;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionModifiedConcurrently;
use App\Pricing\Domain\Promotion\PromotionNotFound;
use App\Pricing\Domain\Promotion\PromotionOverlap;
use App\Pricing\Domain\Promotion\PromotionRepository;

/**
 * The steps the handlers of the promotion writes share. They run under the exclusive lock of the promotion writes.
 */
final class PromotionWrites
{
    /**
     * The stored promotion at the version the change is based on.
     *
     * @throws PromotionNotFound             also for an id that is no UUID
     * @throws PromotionModifiedConcurrently
     */
    public static function load(PromotionRepository $promotions, string $id, int $expectedVersion): Promotion
    {
        try {
            $promotionId = PromotionId::fromString($id);
        } catch (\InvalidArgumentException) {
            throw new PromotionNotFound();
        }
        $promotion = $promotions->find($promotionId) ?? throw new PromotionNotFound();
        if ($promotion->version() !== $expectedVersion) {
            throw new PromotionModifiedConcurrently();
        }

        return $promotion;
    }

    /**
     * @throws PromotionOverlap when another promotion of an overlapping period has a common variant (Q4)
     */
    public static function assertNoOverlap(PromotionRepository $promotions, Promotion $promotion): void
    {
        $conflicting = $promotions->conflictingVariantIds($promotion);
        if ([] !== $conflicting) {
            throw new PromotionOverlap($conflicting);
        }
    }

    /**
     * @param list<string> $variantIds
     *
     * @return list<VariantId>
     */
    public static function variantIds(array $variantIds): array
    {
        return array_map(VariantId::fromString(...), $variantIds);
    }

    public static function actor(?string $id, ?string $name): ?PromotionActor
    {
        return null === $id || null === $name ? null : PromotionActor::staff($id, $name);
    }
}
