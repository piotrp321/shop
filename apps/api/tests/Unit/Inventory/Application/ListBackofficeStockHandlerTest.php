<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Port\CategoryNotFound;
use App\Inventory\Application\Port\DirectoryProduct;
use App\Inventory\Application\Port\InvalidStockListCursor;
use App\Inventory\Application\Query\ListBackofficeStock;
use App\Inventory\Application\Query\ListBackofficeStockHandler;
use App\Inventory\Application\Query\StockListVariant;
use App\Inventory\Domain\Stock\ProductId;
use App\Tests\Unit\Inventory\InMemoryProductDirectory;
use App\Tests\Unit\Inventory\InMemoryStockItemRepository;
use PHPUnit\Framework\TestCase;

final class ListBackofficeStockHandlerTest extends TestCase
{
    private const string BOTTLE = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string BACKPACK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string GEL = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62';

    // Variant ids are never the ids of their products.
    private const string IN_STOCK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01';
    private const string LOW_STOCK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02';
    private const string UNSTORED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03';
    private const string EMPTY_STORED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b04';

    private InMemoryStockItemRepository $stock;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockItemRepository();
        $this->stock->store(self::IN_STOCK, 10, 4, version: 7);
        $this->stock->store(self::LOW_STOCK, 9, 4, version: 2);
        $this->stock->store(self::EMPTY_STORED, 0, 0, version: 0);
    }

    public function testListsTheProductsOfThePageWithTheirVariantsInOrderAndTheirStockAndTheCursorOfTheNextPage(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::BACKPACK, 'Plecak'), $this->product(self::BOTTLE, 'Bidon')], 'next-cursor')
            ->withVariants(self::BACKPACK, [self::UNSTORED => 'czarny', self::IN_STOCK => 'zielony'])
            ->withVariants(self::BOTTLE, [self::LOW_STOCK => '']);

        $list = $this->handler($directory)(new ListBackofficeStock(null, null, null, 25));

        self::assertSame('next-cursor', $list->next);
        self::assertCount(2, $list->items);
        [$backpack, $bottle] = $list->items;
        self::assertSame([self::BACKPACK, 'Plecak'], [$backpack->productId, $backpack->name]);
        self::assertSame(
            [[self::UNSTORED, 'czarny', null], [self::IN_STOCK, 'zielony', 10]],
            array_map(static fn (StockListVariant $variant): array => [$variant->variantId, $variant->label, $variant->stock?->physical], $backpack->variants),
        );
        $in = $backpack->variants[1]->stock;
        self::assertNotNull($in);
        self::assertSame([10, 4, 6, 'in_stock', 7], [$in->physical, $in->reserved, $in->available, $in->level, $in->version]);
        self::assertSame([self::BOTTLE, 'Bidon'], [$bottle->productId, $bottle->name]);
        self::assertCount(1, $bottle->variants);
        self::assertSame([self::LOW_STOCK, ''], [$bottle->variants[0]->variantId, $bottle->variants[0]->label]);
        $low = $bottle->variants[0]->stock;
        self::assertNotNull($low);
        self::assertSame([9, 4, 5, 'low_stock', 2], [$low->physical, $low->reserved, $low->available, $low->level, $low->version]);
        self::assertEquals(new \DateTimeImmutable('2026-11-01 10:00:00+00:00'), $low->updatedAt);
    }

    public function testAVariantWithoutAStoredStockHasNoStock(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::GEL, 'Żel')])->withVariants(self::GEL, [self::UNSTORED => '']);

        $list = $this->handler($directory)(new ListBackofficeStock(null, null, null, 25));

        self::assertNull($list->next);
        self::assertSame(self::UNSTORED, $list->items[0]->variants[0]->variantId);
        self::assertNull($list->items[0]->variants[0]->stock);
    }

    public function testAnEmptyStoredStockKeepsItsVersionSoThatACorrectionCanBeBasedOnIt(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::GEL, 'Żel')])->withVariants(self::GEL, [self::EMPTY_STORED => '']);

        $list = $this->handler($directory)(new ListBackofficeStock(null, null, null, 25));

        $stock = $list->items[0]->variants[0]->stock;
        self::assertNotNull($stock);
        self::assertSame([0, 0, 0, 'out_of_stock', 0], [$stock->physical, $stock->reserved, $stock->available, $stock->level, $stock->version]);
        self::assertEquals(new \DateTimeImmutable('2026-11-01 10:00:00+00:00'), $stock->updatedAt);
    }

    public function testAProductCatalogHasNoVariantsForIsListedWithoutVariants(): void
    {
        $directory = new InMemoryProductDirectory([$this->product(self::GEL, 'Żel'), $this->product(self::BOTTLE, 'Bidon')])
            ->withVariants(self::BOTTLE, [self::LOW_STOCK => '']);

        $list = $this->handler($directory)(new ListBackofficeStock(null, null, null, 25));

        self::assertSame([self::GEL, 'Żel', []], [$list->items[0]->productId, $list->items[0]->name, $list->items[0]->variants]);
        self::assertSame([[self::LOW_STOCK]], $this->stock->manyReads);
    }

    public function testTheVariantsOfThePageAreReadWithOneCallAndTheirStocksWithOneCall(): void
    {
        $directory = new InMemoryProductDirectory([
            $this->product(self::BOTTLE, 'A'),
            $this->product(self::BACKPACK, 'B'),
        ])
            ->withVariants(self::BOTTLE, [self::LOW_STOCK => ''])
            ->withVariants(self::BACKPACK, [self::UNSTORED => 'czarny', self::IN_STOCK => 'zielony']);

        $this->handler($directory)(new ListBackofficeStock(null, null, null, 25));

        self::assertSame([[self::BOTTLE, self::BACKPACK]], $directory->variantRequests);
        self::assertSame([[self::LOW_STOCK, self::UNSTORED, self::IN_STOCK]], $this->stock->manyReads);
    }

    public function testAnEmptyPageReadsNoVariantsAndNoStocks(): void
    {
        $directory = new InMemoryProductDirectory();

        $list = $this->handler($directory)(new ListBackofficeStock('xyz', null, null, 25));

        self::assertSame([], $list->items);
        self::assertNull($list->next);
        self::assertSame([], $directory->variantRequests);
        self::assertSame([], $this->stock->manyReads);
    }

    public function testPassesTheFiltersTheCursorAndThePageSizeToTheDirectory(): void
    {
        $directory = new InMemoryProductDirectory();

        $this->handler($directory)(new ListBackofficeStock('bie', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5aaa', 'cursor', 10));

        self::assertSame([['bie', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5aaa', 'cursor', 10]], $directory->requests);
    }

    public function testAnUnknownCategoryIsNotSwallowed(): void
    {
        $missing = new InMemoryProductDirectory();
        $missing->categoryExists = false;

        try {
            $this->handler($missing)(new ListBackofficeStock(null, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5aaa', null, 25));
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound) {
            self::assertSame([], $this->stock->manyReads);
        }
    }

    public function testAnInvalidCursorIsNotSwallowed(): void
    {
        $invalid = new InMemoryProductDirectory();
        $invalid->cursorValid = false;
        $this->expectException(InvalidStockListCursor::class);

        $this->handler($invalid)(new ListBackofficeStock(null, null, 'garbage', 25));
    }

    private function handler(InMemoryProductDirectory $directory): ListBackofficeStockHandler
    {
        return new ListBackofficeStockHandler($directory, $this->stock);
    }

    private function product(string $id, string $name): DirectoryProduct
    {
        return new DirectoryProduct(ProductId::fromString($id), $name);
    }
}
