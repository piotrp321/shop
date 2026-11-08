<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine;

use App\Pricing\Application\Port\ActivePromotion;
use App\Pricing\Application\Port\ActivePromotions;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use Doctrine\DBAL\Connection;

/**
 * The promotions active at a moment, straight from the tables in one statement: the variants of the promotions along
 * the index (variant_id) of pricing_promotion_variant, with the period of each promotion.
 */
final readonly class DbalActivePromotions implements ActivePromotions
{
    /** A moment with its microseconds, so a comparison with the whole seconds of a period is exact. */
    private const string MOMENT = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $connection)
    {
    }

    public function at(array $variantIds, \DateTimeImmutable $moment): array
    {
        if ([] === $variantIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT pv.variant_id, p.id, p.name, p.percent_off, p.starts_at, p.ends_at '
            .'FROM pricing_promotion_variant pv JOIN pricing_promotion p ON p.id = pv.promotion_id '
            .'WHERE pv.variant_id = ANY(CAST(:ids AS uuid[])) '
            .'AND p.starts_at <= CAST(:at AS timestamptz) AND p.ends_at > CAST(:at AS timestamptz)',
            [
                'ids' => '{'.implode(',', array_map(static fn (VariantId $id): string => $id->toString(), $variantIds)).'}',
                'at' => $moment->format(self::MOMENT),
            ],
        );

        $active = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['variant_id']) && \is_string($row['id']) && \is_string($row['name']) && \is_int($row['percent_off']));
            \assert(\is_string($row['starts_at']) && \is_string($row['ends_at']));
            $active[$row['variant_id']] = new ActivePromotion(
                $row['id'],
                $row['name'],
                PercentOff::of($row['percent_off']),
                PromotionPeriod::of(new \DateTimeImmutable($row['starts_at']), new \DateTimeImmutable($row['ends_at'])),
            );
        }

        return $active;
    }
}
