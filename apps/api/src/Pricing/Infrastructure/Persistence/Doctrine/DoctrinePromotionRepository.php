<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine;

use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\Promotion;
use App\Pricing\Domain\Promotion\PromotionActor;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionModifiedConcurrently;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use App\Pricing\Domain\Promotion\PromotionRepository;
use App\Pricing\Infrastructure\Persistence\Doctrine\Record\PromotionRecord;
use App\Pricing\Infrastructure\Persistence\Doctrine\Record\PromotionVariantRecord;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

/**
 * Promotions through their records (the general rule). The two questions across promotions (the overlaps of Q4 and
 * the price lock of Q13) are single statements on the connection, so they see the stored rows, not the identity map.
 */
final readonly class DoctrinePromotionRepository implements PromotionRepository
{
    /** A moment with its microseconds, so a comparison with the whole seconds of a period is exact. */
    private const string MOMENT = 'Y-m-d H:i:s.uP';

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(PromotionId $id): ?Promotion
    {
        $record = $this->entityManager->find(PromotionRecord::class, $id->toString());

        return null === $record ? null : $this->toDomain($record);
    }

    /**
     * A promotion at version 1 is new; a revised one was read at its version − 1, which the versioned UPDATE checks.
     * The variants are kept in place by id (the primary key is the promotion and the variant): only the removed ones
     * are deleted and only the added ones inserted.
     */
    public function save(Promotion $promotion): void
    {
        $id = $promotion->id()->toString();
        if (1 === $promotion->version()) {
            $record = new PromotionRecord($id);
            $this->entityManager->persist($record);
        } else {
            $record = $this->entityManager->find(PromotionRecord::class, $id) ?? throw new PromotionModifiedConcurrently();
            // The versioned UPDATE compares with this value, so a record cached with a newer version cannot make a
            // stale write pass.
            $record->version = $promotion->version() - 1;
        }

        $record->name = $promotion->name();
        $record->percentOff = $promotion->percentOff()->value();
        $record->startsAt = $promotion->period()->startsAt();
        $record->endsAt = $promotion->period()->endsAt();
        $record->createdAt = $promotion->createdAt();
        $record->createdById = $promotion->createdBy()?->id();
        $record->createdByName = $promotion->createdBy()?->name();
        $record->updatedAt = $promotion->updatedAt();
        $record->updatedById = $promotion->updatedBy()?->id();
        $record->updatedByName = $promotion->updatedBy()?->name();
        $this->syncVariants($record, $promotion->variantIds());

        try {
            $this->entityManager->flush();
        } catch (OptimisticLockException) {
            throw new PromotionModifiedConcurrently();
        }
    }

    public function delete(Promotion $promotion): void
    {
        $record = $this->entityManager->find(PromotionRecord::class, $promotion->id()->toString());
        if (null === $record) {
            return;
        }
        $this->entityManager->remove($record);
        $this->entityManager->flush();
    }

    public function conflictingVariantIds(Promotion $promotion): array
    {
        $variants = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT pv.variant_id FROM pricing_promotion_variant pv JOIN pricing_promotion p ON p.id = pv.promotion_id '
            .'WHERE pv.variant_id = ANY(CAST(:ids AS uuid[])) AND p.id <> CAST(:id AS uuid) '
            .'AND p.starts_at < CAST(:endsAt AS timestamptz) AND p.ends_at > CAST(:startsAt AS timestamptz) ORDER BY pv.variant_id',
            [
                'ids' => self::uuidArray($promotion->variantIds()),
                'id' => $promotion->id()->toString(),
                'startsAt' => $promotion->period()->startsAt()->format(self::MOMENT),
                'endsAt' => $promotion->period()->endsAt()->format(self::MOMENT),
            ],
        );

        return array_map(static function (mixed $id): VariantId {
            \assert(\is_string($id));

            return VariantId::fromString($id);
        }, $variants);
    }

    public function isInActivePromotion(VariantId $variantId, \DateTimeImmutable $at): bool
    {
        return true === $this->entityManager->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM pricing_promotion_variant pv JOIN pricing_promotion p ON p.id = pv.promotion_id '
            .'WHERE pv.variant_id = CAST(:variantId AS uuid) AND p.starts_at <= CAST(:at AS timestamptz) AND p.ends_at > CAST(:at AS timestamptz))',
            ['variantId' => $variantId->toString(), 'at' => $at->format(self::MOMENT)],
        );
    }

    /**
     * @param list<VariantId> $variantIds
     */
    private function syncVariants(PromotionRecord $record, array $variantIds): void
    {
        $wanted = [];
        foreach ($variantIds as $variantId) {
            $wanted[$variantId->toString()] = true;
        }
        foreach ($record->variants->toArray() as $variant) {
            if (isset($wanted[$variant->variantId])) {
                unset($wanted[$variant->variantId]);
            } else {
                $record->variants->removeElement($variant);
            }
        }
        foreach (array_keys($wanted) as $variantId) {
            $record->variants->add(new PromotionVariantRecord($record, $variantId));
        }
    }

    /**
     * A PostgreSQL array literal of validated UUIDs (hex digits and dashes only), bound as one parameter.
     *
     * @param list<VariantId> $ids
     */
    private static function uuidArray(array $ids): string
    {
        return '{'.implode(',', array_map(static fn (VariantId $id): string => $id->toString(), $ids)).'}';
    }

    private function toDomain(PromotionRecord $record): Promotion
    {
        $variantIds = [];
        foreach ($record->variants as $variant) {
            $variantIds[] = VariantId::fromString($variant->variantId);
        }

        return Promotion::reconstitute(
            PromotionId::fromString($record->id),
            $record->name,
            PercentOff::of($record->percentOff),
            PromotionPeriod::of($record->startsAt, $record->endsAt),
            $variantIds,
            $record->createdAt,
            self::actor($record->createdById, $record->createdByName),
            $record->updatedAt,
            self::actor($record->updatedById, $record->updatedByName),
            $record->version,
        );
    }

    private static function actor(?string $id, ?string $name): ?PromotionActor
    {
        return null === $id || null === $name ? null : PromotionActor::staff($id, $name);
    }
}
