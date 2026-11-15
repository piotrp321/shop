<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Query\BackofficeProduct;
use App\Catalog\Application\Query\SearchBackofficeProducts;
use App\Catalog\Application\Query\SearchBackofficeProductsHandler;
use App\Shared\Domain\BoundedCount;
use App\Tests\Support\InMemory\InMemoryCategoryRepository;
use PHPUnit\Framework\TestCase;

/**
 * The total of the product list (and so of the price and stock lists, whose row is a product): only a first page
 * with a next page asks the source to count; a page that holds every match knows the exact number itself.
 */
final class SearchBackofficeProductsHandlerTest extends TestCase
{
    private InMemoryBackofficeProductSource $source;
    private SearchBackofficeProductsHandler $handler;

    protected function setUp(): void
    {
        $this->source = new InMemoryBackofficeProductSource([
            self::product(1),
            self::product(2),
            self::product(3),
        ]);
        $this->handler = new SearchBackofficeProductsHandler($this->source, new InMemoryCategoryRepository());
    }

    public function testAFirstPageWithoutANextOneIsTheWholeResultAndIsNotCounted(): void
    {
        $page = ($this->handler)(new SearchBackofficeProducts(null, null, null, 25));

        self::assertCount(3, $page->items);
        self::assertNull($page->next);
        self::assertEquals(new BoundedCount(3, false), $page->total);
        self::assertSame([], $this->source->counts, 'the rows of the page are the exact count');
    }

    public function testAFirstPageThatEndsExactlyAtItsSizeIsStillTheWholeResult(): void
    {
        $page = ($this->handler)(new SearchBackofficeProducts(null, null, null, 3));

        self::assertNull($page->next);
        self::assertEquals(new BoundedCount(3, false), $page->total);
        self::assertSame([], $this->source->counts);
    }

    public function testAFirstPageWithNoMatchIsAnExactZeroWithoutACount(): void
    {
        $this->source->products = [];

        $page = ($this->handler)(new SearchBackofficeProducts(null, null, null, 25));

        self::assertEquals(new BoundedCount(0, false), $page->total);
        self::assertSame([], $this->source->counts);
    }

    public function testAFirstPageWithANextOneCountsOnceWithTheFilters(): void
    {
        $this->source->total = new BoundedCount(10_000, true);

        $page = ($this->handler)(new SearchBackofficeProducts('buty', null, null, 2, 'draft'));

        self::assertNotNull($page->next);
        self::assertEquals(new BoundedCount(10_000, true), $page->total);
        self::assertCount(1, $this->source->counts);
        self::assertSame('buty', $this->source->counts[0][0]);
        self::assertSame('draft', $this->source->counts[0][2]?->value);
    }

    public function testALaterPageDoesNotCountAndHasNoTotal(): void
    {
        $first = ($this->handler)(new SearchBackofficeProducts(null, null, null, 2));
        self::assertNotNull($first->next);
        $countsOfTheFirstPage = $this->source->counts;
        self::assertNotSame([], $countsOfTheFirstPage);

        $later = ($this->handler)(new SearchBackofficeProducts(null, null, $first->next, 2));

        self::assertNull($later->total);
        self::assertSame($countsOfTheFirstPage, $this->source->counts, 'only the first page counted');
    }

    private static function product(int $n): BackofficeProduct
    {
        return new BackofficeProduct(\sprintf('00000000-0000-4000-8000-%012d', $n), 'Produkt '.$n, 'standard');
    }
}
