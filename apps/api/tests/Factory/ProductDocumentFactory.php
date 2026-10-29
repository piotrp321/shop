<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Catalog\Application\Search\ProductDocument;
use Zenstruck\Foundry\ObjectFactory;

/**
 * Builds search index documents (the read model). The category fields are given by the test.
 *
 * @extends ObjectFactory<ProductDocument>
 */
final class ProductDocumentFactory extends ObjectFactory
{
    public static function class(): string
    {
        return ProductDocument::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        $main = self::faker()->uuid();

        return [
            'id' => self::faker()->uuid(),
            'version' => 1,
            'name' => self::faker()->words(3, true),
            'slug' => self::faker()->unique()->slug(3),
            'shortDescription' => self::faker()->sentence(),
            'mainCategoryId' => $main,
            'categoryIds' => [$main],
            'categoryAncestorIds' => [$main],
            'mainImage' => null,
        ];
    }
}
