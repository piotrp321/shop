<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategorySlug;
use Zenstruck\Foundry\ObjectFactory;

/**
 * Builds Category aggregates through their domain factory methods; tests persist them through the repository.
 * The slug defaults to the id (in the slug format and unique), so categories built without one never collide.
 *
 * @extends ObjectFactory<Category>
 */
final class CategoryFactory extends ObjectFactory
{
    public static function class(): string
    {
        return Category::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => self::faker()->uuid(),
            'name' => self::faker()->words(2, true),
            'slug' => null,
            'parent' => null,
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(self::instantiate(...));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function instantiate(array $attributes): Category
    {
        ['id' => $id, 'name' => $name, 'slug' => $slug, 'parent' => $parent] = $attributes;
        \assert(\is_string($id) && \is_string($name) && (null === $slug || \is_string($slug)) && (null === $parent || $parent instanceof Category));
        $slug = new CategorySlug($slug ?? strtolower($id));

        return null === $parent
            ? Category::createRoot(CategoryId::fromString($id), new CategoryName($name), $slug)
            : Category::createChild(CategoryId::fromString($id), new CategoryName($name), $slug, $parent);
    }
}
