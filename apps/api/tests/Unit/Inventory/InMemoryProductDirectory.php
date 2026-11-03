<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Application\Port\CategoryNotFound;
use App\Inventory\Application\Port\DirectoryPage;
use App\Inventory\Application\Port\DirectoryProduct;
use App\Inventory\Application\Port\InvalidStockListCursor;
use App\Inventory\Application\Port\ProductDirectory;

/**
 * Hands out the page it was given and remembers what it was asked for.
 */
final class InMemoryProductDirectory implements ProductDirectory
{
    /** @var list<array{?string, ?string, ?string, int}> term, category id, cursor and page size of every call */
    public array $requests = [];
    public bool $categoryExists = true;
    public bool $cursorValid = true;

    /**
     * @param list<DirectoryProduct> $products
     */
    public function __construct(private array $products = [], private ?string $next = null)
    {
    }

    public function page(?string $term, ?string $categoryId, ?string $after, int $perPage): DirectoryPage
    {
        $this->requests[] = [$term, $categoryId, $after, $perPage];
        if (!$this->categoryExists) {
            throw new CategoryNotFound();
        }
        if (!$this->cursorValid) {
            throw new InvalidStockListCursor();
        }

        return new DirectoryPage($this->products, $this->next);
    }
}
