<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\Catalog\Domain\Product\VariantValues;
use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Orders\CatalogProduct;
use App\DemoData\Orders\CatalogVariant;
use App\DemoData\Orders\OrderCatalog;
use App\DemoData\Pricing\PriceGenerator;
use App\DemoData\Pricing\PriceTimeline;
use App\DemoData\Pricing\PromotionPlanner;
use App\DemoData\Profile\RunContext;

/**
 * The catalog the orders read, computed from the generators of the earlier steps instead of read from the tables: the
 * same products, variants and price timelines the steps write.
 */
final class GeneratedOrderCatalog implements OrderCatalog
{
    /** @var array<int, CatalogProduct> */
    private array $products = [];
    /** @var array<string, PriceTimeline> */
    private array $timelines = [];

    public function __construct(RunContext $context)
    {
        $directory = \dirname(__DIR__, 3).'/fixtures/demo-data';
        $dictionaries = new Dictionaries($directory);
        $departments = [];
        foreach ($dictionaries->types() as $type) {
            $departments[$type->key] = $type->department();
        }
        $generator = new ProductGenerator($dictionaries, $context->namespace, $context->anchor, $context->profile->products, $context->partSize);
        $prices = new PriceGenerator($dictionaries, $context->namespace, $context->anchor, $context->profile->staff, new PersonDictionaries($directory));
        $byVariant = PromotionPlanner::byVariant(PromotionPlanner::forRun($dictionaries, $context)->plan());

        for ($part = 0; $part < $context->productParts(); ++$part) {
            foreach ($generator->part($part) as $spec) {
                $variants = [];
                foreach ($spec->variants as $variant) {
                    $variants[] = new CatalogVariant($variant->id, VariantValues::fromList($variant->values)->label());
                }
                $this->products[$spec->index] = new CatalogProduct($spec->index, $spec->id, $spec->name, 'published' === $spec->status, $spec->firstPublishedAt, $departments[$spec->typeKey], $spec->taxClass, $variants);
                foreach ($prices->forProduct($spec, $byVariant) as $position => $timeline) {
                    $this->timelines[$spec->variants[$position]->id] = $timeline;
                }
            }
        }
    }

    public function products(array $indexes): array
    {
        return array_intersect_key($this->products, array_flip($indexes));
    }

    public function timelines(array $variantIds): array
    {
        return array_intersect_key($this->timelines, array_flip($variantIds));
    }

    public function productById(string $id): CatalogProduct
    {
        foreach ($this->products as $product) {
            if ($product->id === $id) {
                return $product;
            }
        }

        throw new \OutOfBoundsException('No product '.$id);
    }

    public function timeline(string $variantId): PriceTimeline
    {
        return $this->timelines[$variantId];
    }
}
