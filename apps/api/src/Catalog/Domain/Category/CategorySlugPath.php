<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Category;

/**
 * The address of a category in the store: the slugs from the root to the category joined with `/`, at most
 * {@see CategoryTree::MAX_DEPTH} of them, e.g. `turystyka/biwak/namioty`.
 */
final readonly class CategorySlugPath
{
    /**
     * @param non-empty-list<CategorySlug> $slugs
     */
    private function __construct(private array $slugs)
    {
        if (\count($slugs) > CategoryTree::MAX_DEPTH) {
            throw new \InvalidArgumentException('A category path has at most '.CategoryTree::MAX_DEPTH.' slugs.');
        }
    }

    public static function root(CategorySlug $slug): self
    {
        return new self([$slug]);
    }

    public function child(CategorySlug $slug): self
    {
        return new self([...$this->slugs, $slug]);
    }

    /**
     * @throws \InvalidArgumentException unless the text is 1 to 4 slugs joined with single slashes
     */
    public static function fromString(string $path): self
    {
        $segments = explode('/', $path);
        if (\count($segments) > CategoryTree::MAX_DEPTH) {
            throw new \InvalidArgumentException('A category path has at most '.CategoryTree::MAX_DEPTH.' slugs.');
        }

        return new self(array_map(static fn (string $segment): CategorySlug => new CategorySlug($segment), $segments));
    }

    public function toString(): string
    {
        return implode('/', array_map(static fn (CategorySlug $slug): string => $slug->value(), $this->slugs));
    }
}
