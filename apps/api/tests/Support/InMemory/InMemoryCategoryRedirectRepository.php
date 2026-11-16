<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryRedirectRepository;
use App\Catalog\Domain\Category\CategorySlugPath;

final class InMemoryCategoryRedirectRepository implements CategoryRedirectRepository
{
    /** @var array<string, CategoryId> old path => category */
    private array $redirects = [];

    public function record(array $redirects): void
    {
        foreach ($redirects as $path => $categoryId) {
            $this->redirects[$path] = $categoryId;
        }
    }

    public function forget(array $paths): void
    {
        foreach ($paths as $path) {
            unset($this->redirects[$path]);
        }
    }

    public function find(CategorySlugPath $path): ?CategoryId
    {
        return $this->redirects[$path->toString()] ?? null;
    }

    /**
     * @return array<string, CategoryId>
     */
    public function all(): array
    {
        return $this->redirects;
    }
}
