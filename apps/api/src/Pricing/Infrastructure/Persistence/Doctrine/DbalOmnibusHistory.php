<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine;

use App\Pricing\Application\Port\OmnibusHistory;
use App\Pricing\Domain\Money\Currency;
use App\Pricing\Domain\Money\Money;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\LowestPriorPrice;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PriorPrice;
use App\Pricing\Domain\Promotion\PriorPromotion;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use Doctrine\DBAL\Connection;

/**
 * The input of the Omnibus price straight from the tables, one statement per method for all the variants: the
 * windows are an unnest() of three parallel arrays (variant, start of the window, start of the promotion), joined to
 * the history along its index (variant_id, changed_at, id) and to the promotions of each variant along the index
 * (variant_id) of pricing_promotion_variant. The start of each window comes from the domain
 * ({@see LowestPriorPrice::windowStart()}), not from interval arithmetic in the session's time zone.
 */
final readonly class DbalOmnibusHistory implements OmnibusHistory
{
    private const string MOMENT = 'Y-m-d H:i:s.uP';
    private const string WINDOWS = 'unnest(CAST(:ids AS uuid[]), CAST(:froms AS timestamptz[]), CAST(:untils AS timestamptz[])) AS w(variant_id, window_start, starts_at)';

    public function __construct(private Connection $connection)
    {
    }

    public function prices(array $promotionStarts): array
    {
        if ([] === $promotionStarts) {
            return [];
        }

        // The last entry before the window gives the price at its start; the id breaks a tie within one second
        // (the ids are time-ordered, see DoctrineVariantPriceRepository).
        $rows = $this->connection->fetchAllAssociative(
            'SELECT w.variant_id, h.changed_at, h.gross_amount, h.currency FROM '.self::WINDOWS.' CROSS JOIN LATERAL ('
            .'(SELECT changed_at, id, gross_amount, currency FROM pricing_price_history '
            .'WHERE variant_id = w.variant_id AND changed_at < w.window_start ORDER BY changed_at DESC, id DESC LIMIT 1) '
            .'UNION ALL '
            .'(SELECT changed_at, id, gross_amount, currency FROM pricing_price_history '
            .'WHERE variant_id = w.variant_id AND changed_at >= w.window_start AND changed_at < w.starts_at)'
            .') h ORDER BY w.variant_id, h.changed_at, h.id',
            self::windows($promotionStarts),
        );

        $prices = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['variant_id']) && \is_string($row['changed_at']) && \is_int($row['gross_amount']) && \is_string($row['currency']));
            $prices[$row['variant_id']][] = new PriorPrice(
                new \DateTimeImmutable($row['changed_at'])->setTimezone(new \DateTimeZone('UTC')),
                GrossPrice::of(Money::of($row['gross_amount'], Currency::from($row['currency']))),
            );
        }

        return $prices;
    }

    public function promotions(array $promotionStarts): array
    {
        if ([] === $promotionStarts) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT w.variant_id, p.starts_at, p.ends_at, p.percent_off FROM '.self::WINDOWS.' '
            .'JOIN pricing_promotion_variant pv ON pv.variant_id = w.variant_id '
            .'JOIN pricing_promotion p ON p.id = pv.promotion_id '
            .'WHERE p.starts_at < w.starts_at AND p.ends_at > w.window_start '
            .'ORDER BY w.variant_id, p.starts_at',
            self::windows($promotionStarts),
        );

        $promotions = [];
        foreach ($rows as $row) {
            \assert(\is_string($row['variant_id']) && \is_string($row['starts_at']) && \is_string($row['ends_at']) && \is_int($row['percent_off']));
            $promotions[$row['variant_id']][] = new PriorPromotion(
                PromotionPeriod::of(new \DateTimeImmutable($row['starts_at']), new \DateTimeImmutable($row['ends_at'])),
                PercentOff::of($row['percent_off']),
            );
        }

        return $promotions;
    }

    /**
     * PostgreSQL array literals of validated UUIDs and of formatted moments (quoted), bound as three parameters.
     *
     * @param array<string, \DateTimeImmutable> $promotionStarts
     *
     * @return array{ids: string, froms: string, untils: string}
     */
    private static function windows(array $promotionStarts): array
    {
        $ids = [];
        $froms = [];
        $untils = [];
        foreach ($promotionStarts as $variantId => $start) {
            $ids[] = VariantId::fromString($variantId)->toString();
            $froms[] = '"'.LowestPriorPrice::windowStart($start)->format(self::MOMENT).'"';
            $untils[] = '"'.$start->format(self::MOMENT).'"';
        }

        return ['ids' => '{'.implode(',', $ids).'}', 'froms' => '{'.implode(',', $froms).'}', 'untils' => '{'.implode(',', $untils).'}'];
    }
}
