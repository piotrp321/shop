<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

final readonly class CategoryPath
{
    /**
     * @param non-empty-list<CategoryId> $ids
     */
    private function __construct(private array $ids)
    {
    }

    public static function root(CategoryId $id): self
    {
        return new self([$id]);
    }

    public function child(CategoryId $id): self
    {
        return new self([...$this->ids, $id]);
    }

    public static function fromString(string $path): self
    {
        if (1 !== preg_match('#^/([^/]+/)+$#D', $path)) {
            throw new \InvalidArgumentException('CategoryPath is malformed.');
        }

        $ids = array_map(
            CategoryId::fromString(...),
            explode('/', substr($path, 1, -1)),
        );

        return new self($ids);
    }

    /**
     * @return non-empty-list<CategoryId> from the root, including the category itself
     */
    public function ancestorIds(): array
    {
        return $this->ids;
    }

    public function toString(): string
    {
        return '/'.implode('/', array_map(static fn (CategoryId $id): string => $id->toString(), $this->ids)).'/';
    }
}
