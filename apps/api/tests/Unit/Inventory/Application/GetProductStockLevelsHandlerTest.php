<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Query\GetProductStockLevels;
use App\Inventory\Application\Query\GetProductStockLevelsHandler;
use App\Inventory\Application\Query\InvalidStockLevelsQuery;
use App\Inventory\Application\Query\ProductStockLevel;
use App\Tests\Unit\Inventory\InMemoryProductDirectory;
use App\Tests\Unit\Inventory\InMemoryStockItemRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Transitional until the product offers replace GET /api/v1/availability (VARIANTS-4): the product contract of the
 * store list and of the cart while stock is kept per variant.
 */
final class GetProductStockLevelsHandlerTest extends TestCase
{
    private const string TENT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string SHOES = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string GEL = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62';
    private const string BOTTLE = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a63';
    private const string UNKNOWN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a64';

    // Variant ids are never the ids of their products.
    private const string TENT_DEFAULT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01';
    private const string SHOES_40 = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02';
    private const string SHOES_41 = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03';
    private const string GEL_DEFAULT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b04';
    private const string BOTTLE_DEFAULT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b05';

    private InMemoryStockItemRepository $stock;
    private InMemoryProductDirectory $directory;
    private GetProductStockLevelsHandler $handler;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockItemRepository();
        $this->directory = new InMemoryProductDirectory()
            ->withVariants(self::TENT, [self::TENT_DEFAULT => ''])
            ->withVariants(self::SHOES, [self::SHOES_40 => '40', self::SHOES_41 => '41'])
            ->withVariants(self::GEL, [self::GEL_DEFAULT => ''])
            ->withVariants(self::BOTTLE, [self::BOTTLE_DEFAULT => '']);
        $this->handler = new GetProductStockLevelsHandler($this->directory, $this->stock);
        $this->stock->store(self::TENT_DEFAULT, 10, 4);
        $this->stock->store(self::SHOES_40, 30);
        $this->stock->store(self::SHOES_41, 30);
        $this->stock->store(self::GEL_DEFAULT, 3, 1);
    }

    public function testAProductWithExactlyOneVariantHasTheStockOfThatVariant(): void
    {
        $levels = ($this->handler)(new GetProductStockLevels([self::TENT, self::GEL]));

        self::assertEquals([
            new ProductStockLevel(self::TENT, 6, 'in_stock'),
            new ProductStockLevel(self::GEL, 2, 'low_stock'),
        ], $levels);
    }

    public function testAProductWithMoreVariantsAProductWithoutAStoredStockAndAnUnknownProductAreOutOfStock(): void
    {
        $levels = ($this->handler)(new GetProductStockLevels([self::SHOES, self::BOTTLE, self::UNKNOWN, self::TENT]));

        self::assertEquals([
            new ProductStockLevel(self::SHOES, 0, 'out_of_stock'),
            new ProductStockLevel(self::BOTTLE, 0, 'out_of_stock'),
            new ProductStockLevel(self::UNKNOWN, 0, 'out_of_stock'),
            new ProductStockLevel(self::TENT, 6, 'in_stock'),
        ], $levels);
    }

    public function testTheVariantsAreReadWithOneCallAndTheStocksOfTheSingleVariantsWithOneReadAndARepeatedIdAnswersOnce(): void
    {
        $levels = ($this->handler)(new GetProductStockLevels([self::TENT, strtoupper(self::SHOES), self::GEL, self::TENT]));

        self::assertSame([self::TENT, self::SHOES, self::GEL], array_map(static fn (ProductStockLevel $level): string => $level->productId, $levels));
        self::assertSame([[self::TENT, self::SHOES, self::GEL]], $this->directory->variantRequests);
        self::assertSame([[self::TENT_DEFAULT, self::GEL_DEFAULT]], $this->stock->manyReads);
        self::assertSame([], $this->stock->locks);
    }

    public function testNoSingleVariantReadsNoStock(): void
    {
        $levels = ($this->handler)(new GetProductStockLevels([self::SHOES, self::UNKNOWN]));

        self::assertCount(2, $levels);
        self::assertSame([], $this->stock->manyReads);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'no product' => [[]];
        yield '101 products' => [array_map(static fn (int $i): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), range(1, 101))];
        yield 'not a product id' => [[self::TENT, 'not-a-uuid']];
    }

    /**
     * @param list<string> $ids
     */
    #[DataProvider('invalidQueries')]
    public function testInvalidQueryIsRefusedWithoutReading(array $ids): void
    {
        try {
            ($this->handler)(new GetProductStockLevels($ids));
            self::fail('Expected InvalidStockLevelsQuery.');
        } catch (InvalidStockLevelsQuery) {
        }

        self::assertSame([], $this->directory->variantRequests);
        self::assertSame([], $this->stock->manyReads);
    }
}
