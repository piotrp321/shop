<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

use App\Catalog\Domain\Product\VariantValues;
use App\DemoData\Copy\Sql;
use App\DemoData\Identity\DemoUuid;
use App\DemoData\Pricing\PriceEntry;
use App\DemoData\Pricing\PriceTimeline;
use App\DemoData\Pricing\PromotionSpec;
use App\Pricing\Domain\Promotion\PercentOff;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use Doctrine\DBAL\Connection;

/**
 * The catalog of the orders read from the tables the earlier steps wrote (steps 1 and 3), a few statements per part:
 * the products of the candidate indexes (ids UUID v5 `product:<i>`) with their department (the root of the primary
 * category) and variants, then the history and the promotions of the chosen variants. What the orders see is what the
 * shop holds, so the price of a line is the one Pricing would have given at the placement.
 */
final readonly class DbalOrderCatalog implements OrderCatalog
{
    public function __construct(
        private Connection $connection,
        private string $namespace,
    ) {
    }

    public function products(array $indexes): array
    {
        if ([] === $indexes) {
            return [];
        }
        $byId = [];
        foreach ($indexes as $index) {
            $byId[DemoUuid::v5($this->namespace, 'product:'.$index)] = $index;
        }
        $ids = Sql::textArray(array_keys($byId));

        $variants = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, product_id, option_values FROM catalog_product_variant WHERE product_id = ANY(CAST(? AS uuid[])) ORDER BY product_id, position', [$ids]) as $row) {
            $values = json_decode(Sql::string($row['option_values']), true, flags: \JSON_THROW_ON_ERROR);
            if (!\is_array($values)) {
                throw new \RuntimeException('The values of a variant are not a list.');
            }
            $variants[Sql::string($row['product_id'])][] = new CatalogVariant(Sql::string($row['id']), VariantValues::stored(array_map(Sql::string(...), array_values($values)))->label());
        }

        $products = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT p.id, p.name, p.status, p.first_published_at, p.tax_class, root.slug AS department FROM catalog_product p
             JOIN catalog_category c ON c.id = p.primary_category_id
             JOIN catalog_category root ON root.id = CAST(split_part(c.path, \'/\', 2) AS uuid)
             WHERE p.id = ANY(CAST(? AS uuid[]))',
            [$ids],
        ) as $row) {
            $id = Sql::string($row['id']);
            $index = $byId[$id];
            $products[$index] = new CatalogProduct(
                $index,
                $id,
                Sql::string($row['name']),
                'published' === $row['status'],
                null === $row['first_published_at'] ? null : new \DateTimeImmutable(Sql::string($row['first_published_at'])),
                Sql::string($row['department']),
                Sql::string($row['tax_class']),
                $variants[$id] ?? [],
            );
        }

        return $products;
    }

    public function timelines(array $variantIds): array
    {
        if ([] === $variantIds) {
            return [];
        }
        $ids = Sql::textArray($variantIds);

        $promotions = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT v.variant_id, p.id, p.name, p.percent_off, p.starts_at, p.ends_at, p.created_at FROM pricing_promotion_variant v
             JOIN pricing_promotion p ON p.id = v.promotion_id WHERE v.variant_id = ANY(CAST(? AS uuid[])) ORDER BY p.starts_at, p.id',
            [$ids],
        ) as $row) {
            $variantId = Sql::string($row['variant_id']);
            $promotions[$variantId][] = new PromotionSpec(
                Sql::string($row['id']),
                Sql::string($row['name']),
                PercentOff::of(Sql::int($row['percent_off'])),
                PromotionPeriod::of(new \DateTimeImmutable(Sql::string($row['starts_at'])), new \DateTimeImmutable(Sql::string($row['ends_at']))),
                new \DateTimeImmutable(Sql::string($row['created_at'])),
                [$variantId],
            );
        }

        $entries = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT variant_id, id, changed_at, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, source, actor_id, actor_name FROM pricing_price_history
             WHERE variant_id = ANY(CAST(? AS uuid[])) ORDER BY variant_id, changed_at, id',
            [$ids],
        ) as $row) {
            $entries[Sql::string($row['variant_id'])][] = new PriceEntry(
                Sql::string($row['id']),
                new \DateTimeImmutable(Sql::string($row['changed_at'])),
                Sql::int($row['gross_amount']),
                Sql::int($row['net_amount']),
                Sql::int($row['vat_amount']),
                Sql::int($row['vat_rate_bp']),
                Sql::string($row['tax_class']),
                Sql::string($row['source']),
                null === $row['actor_id'] ? null : Sql::string($row['actor_id']),
                null === $row['actor_name'] ? null : Sql::string($row['actor_name']),
            );
        }

        $timelines = [];
        foreach ($entries as $variantId => $list) {
            $timelines[$variantId] = new PriceTimeline($list, $promotions[$variantId] ?? []);
        }

        return $timelines;
    }
}
