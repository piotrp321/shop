<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Catalog\Domain\Category\CategoryId;

final readonly class ProductCategories
{
    private const int MAX_ADDITIONAL = 10;

    /**
     * @param list<CategoryId> $additional
     */
    private function __construct(private CategoryId $main, private array $additional)
    {
    }

    /**
     * @param list<CategoryId> $additional
     */
    public static function create(CategoryId $main, array $additional = []): self
    {
        if (\count($additional) > self::MAX_ADDITIONAL) {
            throw new \InvalidArgumentException('A product can have at most '.self::MAX_ADDITIONAL.' additional categories.');
        }

        $seen = [$main->toString() => true];
        foreach ($additional as $id) {
            if (isset($seen[$id->toString()])) {
                throw new \InvalidArgumentException('A category can appear in a product only once.');
            }
            $seen[$id->toString()] = true;
        }

        return new self($main, $additional);
    }

    public function main(): CategoryId
    {
        return $this->main;
    }

    /**
     * @return list<CategoryId>
     */
    public function additional(): array
    {
        return $this->additional;
    }

    /**
     * @return list<CategoryId> the main category first, then the additional ones
     */
    public function all(): array
    {
        return [$this->main, ...$this->additional];
    }
}
