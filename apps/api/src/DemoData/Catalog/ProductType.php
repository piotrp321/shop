<?php

declare(strict_types=1);

namespace App\DemoData\Catalog;

use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\TaxClassCode;

/**
 * A product type of the dictionary: what a product of it is called, where it lives, how it varies and how it is priced
 * and described (fixtures/demo-data/product-types.yaml).
 */
final readonly class ProductType
{
    /** The key of the type (the slug of its name), the same in the rows of every step. */
    public string $key;

    /**
     * @param list<TypeAxis> $axes
     * @param list<string>   $features
     * @param positive-int   $weight
     */
    public function __construct(
        public string $name,
        public string $leaf,
        public TaxClassCode $taxClass,
        public array $axes,
        public float $single,
        public int $priceMin,
        public int $priceMax,
        public string $photoGroup,
        public int $weight,
        public array $features,
    ) {
        $this->key = Slug::slugify($name);
    }

    public function department(): string
    {
        return explode('/', $this->leaf)[0];
    }

    public function combinationCount(): int
    {
        $count = 1;
        foreach ($this->axes as $axis) {
            $count *= \count($axis->values);
        }

        return $count;
    }
}
