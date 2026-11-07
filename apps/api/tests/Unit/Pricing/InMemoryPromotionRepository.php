<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionModifiedConcurrently;
use App\Pricing\Domain\Promotion\PromotionRepository;

/**
 * Keeps promotions like the database does: find() returns a copy, save() checks the version it was read with.
 */
final class InMemoryPromotionRepository implements PromotionRepository
{
    /** @var array<string, Promotion> */
    private array $promotions = [];
    public int $writes = 0;

    public function find(PromotionId $id): ?Promotion
    {
        $stored = $this->promotions[$id->toString()] ?? null;

        return null === $stored ? null : self::copy($stored);
    }

    public function save(Promotion $promotion): void
    {
        $stored = $this->promotions[$promotion->id()->toString()] ?? null;
        if ($promotion->version() - 1 !== ($stored?->version() ?? 0)) {
            throw new PromotionModifiedConcurrently();
        }
        $this->promotions[$promotion->id()->toString()] = self::copy($promotion);
        ++$this->writes;
    }

    public function delete(Promotion $promotion): void
    {
        unset($this->promotions[$promotion->id()->toString()]);
        ++$this->writes;
    }

    public function conflictingVariantIds(Promotion $promotion): array
    {
        $conflicting = [];
        foreach ($this->promotions as $other) {
            if ($other->id()->equals($promotion->id()) || !$other->period()->overlaps($promotion->period())) {
                continue;
            }
            foreach ($other->variantIds() as $variantId) {
                $conflicting[$variantId->toString()] = $variantId;
            }
        }
        $result = array_values(array_filter($promotion->variantIds(), static fn (VariantId $id): bool => isset($conflicting[$id->toString()])));
        usort($result, static fn (VariantId $a, VariantId $b): int => strcmp($a->toString(), $b->toString()));

        return $result;
    }

    public function isInActivePromotion(VariantId $variantId, \DateTimeImmutable $at): bool
    {
        foreach ($this->promotions as $promotion) {
            if (!$promotion->period()->isActiveAt($at)) {
                continue;
            }
            foreach ($promotion->variantIds() as $id) {
                if ($id->equals($variantId)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Stores a promotion as if it had been saved earlier.
     */
    public function store(Promotion $promotion): void
    {
        $this->promotions[$promotion->id()->toString()] = self::copy($promotion);
    }

    private static function copy(Promotion $promotion): Promotion
    {
        return Promotion::reconstitute(
            $promotion->id(),
            $promotion->name(),
            $promotion->percentOff(),
            $promotion->period(),
            $promotion->variantIds(),
            $promotion->createdAt(),
            $promotion->createdBy(),
            $promotion->updatedAt(),
            $promotion->updatedBy(),
            $promotion->version(),
        );
    }
}
