<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use Zenstruck\Foundry\ObjectFactory;

/**
 * Builds Category aggregates through their domain factory methods; tests persist them through the repository.
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
        ['id' => $id, 'name' => $name, 'parent' => $parent] = $attributes;
        \assert(\is_string($id) && \is_string($name) && (null === $parent || $parent instanceof Category));

        return null === $parent
            ? Category::createRoot(CategoryId::fromString($id), new CategoryName($name))
            : Category::createChild(CategoryId::fromString($id), new CategoryName($name), $parent);
    }
}
