<?php

declare(strict_types=1);

namespace App\DemoData\Pricing;

use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Catalog\ProductSpec;
use App\DemoData\Copy\CopyWriter;
use App\DemoData\Copy\Sql;
use App\DemoData\Profile\RunContext;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Step 3 of the generator: the price and the price history of every generated variant, the ended promotions and the
 * historical discount codes, written with COPY (no aggregates, no events, no outbox: the search index and the caches
 * are refreshed by the end of bin/generate-demo). The rows are the ones the application leaves: a variant has its
 * current price (version 1, 2 after a change) and the history entries, a promotion keeps its period, percent and
 * variants (its status is never stored, and an ended promotion leaves no history entry), a code its terms.
 *
 * Tables written: pricing_variant_price, pricing_price_history, pricing_promotion, pricing_promotion_variant,
 * pricing_discount_code.
 */
final readonly class PricingLoader
{
    public function __construct(
        private Connection $connection,
        private CopyWriter $copy,
        private Dictionaries $dictionaries,
        private PersonDictionaries $people,
    ) {
    }

    /**
     * Writes the prices of the parts `worker`, `worker + workers`, … of the products (the parts of the products step),
     * each in a transaction of its own. Needs the products of the parts. Every process plans the promotions itself,
     * since a price never changes inside a promotion of its variant.
     *
     * @param \Closure(int, int, int): void|null $progress called after each part with the part, its variants and its history entries
     *
     * @return array{parts: int, variants: int, entries: int}
     *
     * @throws \RuntimeException when the products of a part are not in the table
     */
    public function loadPrices(RunContext $context, int $worker, int $workers, ?\Closure $progress = null): array
    {
        $products = $this->products($context);
        $prices = new PriceGenerator($this->dictionaries, $context->namespace, $context->anchor, $context->profile->staff, $this->people);
        $byVariant = PromotionPlanner::byVariant(PromotionPlanner::forRun($this->dictionaries, $context)->plan());
        $totals = ['parts' => 0, 'variants' => 0, 'entries' => 0];

        for ($part = $worker; $part < $context->productParts(); $part += $workers) {
            [$variants, $entries] = $this->connection->transactional(fn (): array => $this->loadPart($products, $prices, $byVariant, $part));
            ++$totals['parts'];
            $totals['variants'] += $variants;
            $totals['entries'] += $entries;
            if (null !== $progress) {
                $progress($part, $variants, $entries);
            }
        }

        return $totals;
    }

    /**
     * Writes the promotions with their variants and the discount codes, in one transaction.
     *
     * @return array{promotions: int, variants: int, codes: int}
     */
    public function loadPromotions(RunContext $context): array
    {
        $promotions = PromotionPlanner::forRun($this->dictionaries, $context)->plan();
        $codes = new DiscountCodePlan($context->namespace, $context->anchor)->codes();

        $variants = 0;
        $this->connection->transactional(function () use ($promotions, $codes, &$variants): void {
            $this->copy->relaxDurability();
            $this->copy->copy(
                'pricing_promotion',
                ['id', 'name', 'percent_off', 'starts_at', 'ends_at', 'created_at', 'created_by_id', 'created_by_name', 'updated_at', 'updated_by_id', 'updated_by_name', 'version'],
                array_map(static fn (PromotionSpec $promotion): array => [
                    $promotion->id, $promotion->name, $promotion->percentOff->value(), $promotion->period->startsAt(), $promotion->period->endsAt(),
                    $promotion->createdAt, null, null, $promotion->createdAt, null, null, 1,
                ], $promotions),
            );
            $variants = $this->copy->copy('pricing_promotion_variant', ['promotion_id', 'variant_id'], $this->promotionVariantRows($promotions));
            $this->copy->copy(
                'pricing_discount_code',
                ['id', 'code', 'kind', 'percent_off', 'amount_gross', 'minimum_order_gross', 'starts_at', 'ends_at', 'usage_limit', 'enabled', 'created_at', 'created_by_id', 'created_by_name', 'updated_at', 'updated_by_id', 'updated_by_name', 'version'],
                array_map(static fn (DiscountCodeSpec $code): array => [
                    $code->id, $code->code->toString(), $code->value->kind()->value,
                    $code->value->percentOff()?->value(), $code->value->amountOff()?->amount(), $code->minimumOrder?->gross(),
                    $code->period->startsAt(), $code->period->endsAt(), $code->usageLimit?->value(), $code->enabled,
                    $code->createdAt, null, null, $code->createdAt, null, null, 1,
                ], $codes),
            );
        });

        return ['promotions' => \count($promotions), 'variants' => $variants, 'codes' => \count($codes)];
    }

    private function products(RunContext $context): ProductGenerator
    {
        return new ProductGenerator($this->dictionaries, $context->namespace, $context->anchor, $context->profile->products, $context->partSize);
    }

    /**
     * @param array<string, list<PromotionSpec>> $byVariant
     *
     * @return array{int, int} the variants and the history entries written
     */
    private function loadPart(ProductGenerator $products, PriceGenerator $prices, array $byVariant, int $part): array
    {
        $this->copy->relaxDurability();

        $specs = iterator_to_array($products->part($part), false);
        $this->assertVariantsExist($specs, $part);

        $timelines = [];
        foreach ($specs as $spec) {
            foreach ($prices->forProduct($spec, $byVariant) as $position => $timeline) {
                $timelines[$spec->variants[$position]->id] = $timeline;
            }
        }

        $this->copy->copy(
            'pricing_variant_price',
            ['variant_id', 'currency', 'gross_amount', 'net_amount', 'vat_amount', 'vat_rate_bp', 'tax_class', 'version', 'updated_at'],
            $this->priceRows($timelines),
        );
        $entries = $this->copy->copy(
            'pricing_price_history',
            ['id', 'variant_id', 'currency', 'gross_amount', 'net_amount', 'vat_amount', 'vat_rate_bp', 'tax_class', 'changed_at', 'source', 'actor_id', 'actor_name'],
            $this->historyRows($timelines),
        );

        return [\count($timelines), $entries];
    }

    /**
     * @param list<ProductSpec> $specs
     */
    private function assertVariantsExist(array $specs, int $part): void
    {
        $ids = [];
        foreach ($specs as $spec) {
            foreach ($spec->variants as $variant) {
                $ids[] = $variant->id;
            }
        }
        if ([] === $ids) {
            return;
        }
        $found = Sql::int($this->connection->fetchOne('SELECT count(*) FROM catalog_product_variant WHERE id IN (?)', [$ids], [ArrayParameterType::STRING]));
        if ($found !== \count($ids)) {
            throw new \RuntimeException(\sprintf('The part %d of the products has %d of its %d variants in the table: run demo-data:products first.', $part, $found, \count($ids)));
        }
    }

    /**
     * @param array<string, PriceTimeline> $timelines by the id of the variant
     *
     * @return \Generator<int, list<mixed>>
     */
    private function priceRows(array $timelines): \Generator
    {
        foreach ($timelines as $variantId => $timeline) {
            $entries = $timeline->entries();
            $current = $entries[array_key_last($entries)];

            yield [$variantId, 'PLN', $current->gross, $current->net, $current->vat, $current->rateBasisPoints, $current->taxClass, \count($entries), $current->changedAt];
        }
    }

    /**
     * @param array<string, PriceTimeline> $timelines by the id of the variant
     *
     * @return \Generator<int, list<mixed>>
     */
    private function historyRows(array $timelines): \Generator
    {
        foreach ($timelines as $variantId => $timeline) {
            foreach ($timeline->entries() as $entry) {
                yield [$entry->id, $variantId, 'PLN', $entry->gross, $entry->net, $entry->vat, $entry->rateBasisPoints, $entry->taxClass, $entry->changedAt, $entry->source, $entry->actorId, $entry->actorName];
            }
        }
    }

    /**
     * @param list<PromotionSpec> $promotions
     *
     * @return \Generator<int, list<mixed>>
     */
    private function promotionVariantRows(array $promotions): \Generator
    {
        foreach ($promotions as $promotion) {
            foreach ($promotion->variantIds as $variantId) {
                yield [$promotion->id, $variantId];
            }
        }
    }
}
