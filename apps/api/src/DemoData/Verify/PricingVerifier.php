<?php

declare(strict_types=1);

namespace App\DemoData\Verify;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Copy\Sql;
use App\DemoData\Pricing\DiscountCodePlan;
use App\DemoData\Pricing\DiscountCodeSpec;
use App\DemoData\Pricing\PromotionPlanner;
use App\DemoData\Pricing\PromotionSpec;
use App\DemoData\Profile\RunContext;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use Doctrine\DBAL\Connection;

/**
 * The price checks of demo-data:verify (step 3): every generated variant has a current price and a history that ends
 * with it, the net and VAT of a sample of prices are the ones of the Pricing rule, the generated promotions are the ones
 * of the plan, all ended, with at most 1000 variants, none overlapping on a variant, none with a variant that had no
 * price at its start or a price changed inside it, and the generated discount codes are the planned ones, all ended.
 * Returns the violations; none means the prices are consistent.
 */
final readonly class PricingVerifier
{
    private const int SAMPLE = 2_000;

    public function __construct(
        private Connection $connection,
        private Dictionaries $dictionaries,
        private SeedCatalog $seed,
    ) {
    }

    /**
     * @return list<string>
     */
    public function violations(RunContext $context): array
    {
        $seedSlugs = Sql::textArray(array_keys($this->seed->products()));
        $promotions = PromotionPlanner::forRun($this->dictionaries, $context)->plan();
        $promotionIds = Sql::textArray(array_map(static fn (PromotionSpec $promotion): string => $promotion->id, $promotions));
        $codes = new DiscountCodePlan($context->namespace, $context->anchor)->codes();
        $codeIds = Sql::textArray(array_map(static fn (DiscountCodeSpec $code): string => $code->id, $codes));
        $anchor = $context->anchor->format('Y-m-d H:i:sP');

        return [
            ...$this->variantsWithout('pricing_variant_price', 'A variant has no current price', $seedSlugs),
            ...$this->variantsWithout('pricing_price_history', 'A variant has no price history entry', $seedSlugs),
            ...$this->currentPriceNotLastEntry($seedSlugs),
            ...$this->pricesWithoutVariant(),
            ...$this->breakdowns($seedSlugs),
            ...$this->promotionCount(\count($promotions), $promotionIds),
            ...$this->promotionsNotEnded($promotionIds, $anchor),
            ...$this->promotionsTooBig($promotionIds),
            ...$this->overlappingPromotions($promotionIds),
            ...$this->promotedWithoutPrice($promotionIds),
            ...$this->changedInPromotion($promotionIds),
            ...$this->codes(\count($codes), $codeIds, $anchor),
        ];
    }

    /**
     * @return list<string>
     */
    private function variantsWithout(string $table, string $what, string $seedSlugs): array
    {
        /** @var list<string> $slugs */
        $slugs = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT p.slug FROM catalog_product_variant v JOIN catalog_product p ON p.id = v.product_id
             WHERE NOT (p.slug = ANY(?::text[])) AND NOT EXISTS (SELECT 1 FROM '.$table.' t WHERE t.variant_id = v.id) ORDER BY p.slug LIMIT '.(Listing::SHOWN + 1),
            [$seedSlugs],
        );

        return Listing::of($what.' (products)', $slugs);
    }

    /**
     * @return list<string>
     */
    private function currentPriceNotLastEntry(string $seedSlugs): array
    {
        /** @var list<string> $slugs */
        $slugs = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT p.slug FROM pricing_variant_price pr JOIN catalog_product_variant v ON v.id = pr.variant_id JOIN catalog_product p ON p.id = v.product_id
             JOIN LATERAL (SELECT * FROM pricing_price_history h WHERE h.variant_id = pr.variant_id ORDER BY h.changed_at DESC, h.id DESC LIMIT 1) l ON true
             WHERE NOT (p.slug = ANY(?::text[]))
               AND (l.gross_amount, l.net_amount, l.vat_amount, l.vat_rate_bp, l.tax_class, l.currency, l.changed_at) IS DISTINCT FROM (pr.gross_amount, pr.net_amount, pr.vat_amount, pr.vat_rate_bp, pr.tax_class, pr.currency, pr.updated_at)
             ORDER BY p.slug LIMIT '.(Listing::SHOWN + 1),
            [$seedSlugs],
        );

        return Listing::of('The current price of a variant is not the last entry of its history (products)', $slugs);
    }

    /**
     * @return list<string>
     */
    private function pricesWithoutVariant(): array
    {
        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT pr.variant_id FROM pricing_variant_price pr WHERE NOT EXISTS (SELECT 1 FROM catalog_product_variant v WHERE v.id = pr.variant_id) ORDER BY pr.variant_id LIMIT '.(Listing::SHOWN + 1),
        );

        return Listing::of('A price belongs to a variant that does not exist (variants)', $ids);
    }

    /**
     * Net, VAT and rate of the first prices of the generated variants against the Pricing rule (PriceBreakdown, at the
     * rate of the tax class), so a price the generator wrote with its own rounding or an outdated rate is found.
     *
     * @return list<string>
     */
    private function breakdowns(string $seedSlugs): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT pr.variant_id, pr.gross_amount, pr.net_amount, pr.vat_amount, pr.vat_rate_bp, pr.tax_class FROM pricing_variant_price pr
             JOIN catalog_product_variant v ON v.id = pr.variant_id JOIN catalog_product p ON p.id = v.product_id
             WHERE NOT (p.slug = ANY(?::text[])) ORDER BY pr.variant_id LIMIT '.self::SAMPLE,
            [$seedSlugs],
        );
        $rates = new PolishVatRates();

        $wrong = [];
        foreach ($rows as $row) {
            try {
                $expected = PriceBreakdown::fromGross(GrossPrice::pln(Sql::int($row['gross_amount'])), $rates->rateFor(TaxClass::fromString(Sql::string($row['tax_class']))));
                $same = $expected->net()->amount() === Sql::int($row['net_amount'])
                    && $expected->vat()->amount() === Sql::int($row['vat_amount'])
                    && $expected->rate()->equals(VatRate::fromBasisPoints(Sql::int($row['vat_rate_bp'])));
            } catch (\DomainException|\InvalidArgumentException) {
                $same = false;
            }
            if (!$same) {
                $wrong[] = Sql::string($row['variant_id']);
            }
        }

        return Listing::of('A price does not break down by the rule of Pricing (net, VAT and rate of its tax class) (variants)', $wrong);
    }

    /**
     * @return list<string>
     */
    private function promotionCount(int $planned, string $ids): array
    {
        $found = Sql::int($this->connection->fetchOne('SELECT count(*) FROM pricing_promotion WHERE id = ANY(?::uuid[])', [$ids]));

        return $found === $planned ? [] : [\sprintf('The generator plans %d promotions, the table has %d.', $planned, $found)];
    }

    /**
     * @return list<string>
     */
    private function promotionsNotEnded(string $ids, string $anchor): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn(
            'SELECT name FROM pricing_promotion WHERE id = ANY(?::uuid[]) AND ends_at > ?::timestamptz ORDER BY name LIMIT '.(Listing::SHOWN + 1),
            [$ids, $anchor],
        );

        return Listing::of('A generated promotion has not ended before the anchor', $names);
    }

    /**
     * @return list<string>
     */
    private function promotionsTooBig(string $ids): array
    {
        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn(
            'SELECT p.name FROM pricing_promotion p JOIN pricing_promotion_variant v ON v.promotion_id = p.id WHERE p.id = ANY(?::uuid[]) GROUP BY p.id, p.name HAVING count(*) > '.PromotionPlanner::MAX_VARIANTS.' ORDER BY p.name LIMIT '.(Listing::SHOWN + 1),
            [$ids],
        );

        return Listing::of('A generated promotion has more than '.PromotionPlanner::MAX_VARIANTS.' variants', $names);
    }

    /**
     * @return list<string>
     */
    private function overlappingPromotions(string $ids): array
    {
        /** @var list<string> $variants */
        $variants = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT a.variant_id FROM pricing_promotion_variant a JOIN pricing_promotion_variant b ON b.variant_id = a.variant_id AND b.promotion_id > a.promotion_id
             JOIN pricing_promotion pa ON pa.id = a.promotion_id JOIN pricing_promotion pb ON pb.id = b.promotion_id
             WHERE pa.id = ANY(?::uuid[]) AND pb.id = ANY(?::uuid[]) AND pa.starts_at < pb.ends_at AND pb.starts_at < pa.ends_at ORDER BY a.variant_id LIMIT '.(Listing::SHOWN + 1),
            [$ids, $ids],
        );

        return Listing::of('A variant is in two generated promotions with overlapping periods (variants)', $variants);
    }

    /**
     * @return list<string>
     */
    private function promotedWithoutPrice(string $ids): array
    {
        /** @var list<string> $variants */
        $variants = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT v.variant_id FROM pricing_promotion p JOIN pricing_promotion_variant v ON v.promotion_id = p.id
             WHERE p.id = ANY(?::uuid[]) AND NOT EXISTS (SELECT 1 FROM pricing_price_history h WHERE h.variant_id = v.variant_id AND h.changed_at <= p.starts_at) ORDER BY v.variant_id LIMIT '.(Listing::SHOWN + 1),
            [$ids],
        );

        return Listing::of('A promoted variant has no price when its promotion starts (variants)', $variants);
    }

    /**
     * @return list<string>
     */
    private function changedInPromotion(string $ids): array
    {
        /** @var list<string> $variants */
        $variants = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT h.variant_id FROM pricing_promotion p JOIN pricing_promotion_variant v ON v.promotion_id = p.id JOIN pricing_price_history h ON h.variant_id = v.variant_id
             WHERE p.id = ANY(?::uuid[]) AND h.changed_at >= p.starts_at AND h.changed_at < p.ends_at ORDER BY h.variant_id LIMIT '.(Listing::SHOWN + 1),
            [$ids],
        );

        return Listing::of('A price was changed while its variant was in a promotion (variants)', $variants);
    }

    /**
     * @return list<string>
     */
    private function codes(int $planned, string $ids, string $anchor): array
    {
        $found = Sql::int($this->connection->fetchOne('SELECT count(*) FROM pricing_discount_code WHERE id = ANY(?::uuid[])', [$ids]));
        $violations = $found === $planned ? [] : [\sprintf('The generator plans %d discount codes, the table has %d.', $planned, $found)];

        /** @var list<string> $texts */
        $texts = $this->connection->fetchFirstColumn(
            'SELECT code FROM pricing_discount_code WHERE id = ANY(?::uuid[]) AND (ends_at IS NULL OR ends_at > ?::timestamptz) ORDER BY code LIMIT '.(Listing::SHOWN + 1),
            [$ids, $anchor],
        );

        return [...$violations, ...Listing::of('A generated discount code has not ended before the anchor', $texts)];
    }
}
