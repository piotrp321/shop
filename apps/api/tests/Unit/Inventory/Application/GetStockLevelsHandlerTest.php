<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Query\GetStockLevels;
use App\Inventory\Application\Query\GetStockLevelsHandler;
use App\Inventory\Application\Query\InvalidStockLevelsQuery;
use App\Inventory\Application\Query\StockLevel;
use App\Tests\Unit\Inventory\InMemoryStockItemRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GetStockLevelsHandlerTest extends TestCase
{
    private const string IN_STOCK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string LOW_STOCK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string OUT_OF_STOCK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62';
    private const string WITHOUT_STOCK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a63';

    private InMemoryStockItemRepository $stock;
    private GetStockLevelsHandler $handler;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockItemRepository();
        $this->handler = new GetStockLevelsHandler($this->stock);
    }

    public function testEveryRequestedVariantHasALevelInTheOrderOfTheRequest(): void
    {
        $this->stock->store(self::IN_STOCK, 10, 4, version: 7);
        $this->stock->store(self::LOW_STOCK, 9, 4, version: 2);
        $this->stock->store(self::OUT_OF_STOCK, 3, 3);

        $levels = ($this->handler)(new GetStockLevels([self::WITHOUT_STOCK, self::LOW_STOCK, self::OUT_OF_STOCK, self::IN_STOCK]));

        self::assertEquals([
            new StockLevel(self::WITHOUT_STOCK, 0, 0, 0, 'out_of_stock', null),
            new StockLevel(self::LOW_STOCK, 9, 4, 5, 'low_stock', 2),
            new StockLevel(self::OUT_OF_STOCK, 3, 3, 0, 'out_of_stock', 1),
            new StockLevel(self::IN_STOCK, 10, 4, 6, 'in_stock', 7),
        ], $levels);
    }

    public function testAllVariantsAreReadInOneCallAndARepeatedIdAnswersOnce(): void
    {
        $this->stock->store(self::IN_STOCK, 10);

        $levels = ($this->handler)(new GetStockLevels([self::IN_STOCK, strtoupper(self::WITHOUT_STOCK), self::IN_STOCK]));

        self::assertSame([self::IN_STOCK, self::WITHOUT_STOCK], array_map(static fn (StockLevel $l): string => $l->variantId, $levels));
        self::assertSame([[self::IN_STOCK, self::WITHOUT_STOCK]], $this->stock->manyReads);
        self::assertSame([], $this->stock->locks);
    }

    /**
     * 48 products of 100 variants: the most a page of the store list can ask for.
     */
    public function test4800VariantsAreAccepted(): void
    {
        $ids = array_map(static fn (int $i): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), range(1, 4800));

        self::assertCount(4800, ($this->handler)(new GetStockLevels($ids)));
        self::assertCount(1, $this->stock->manyReads);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'no variant' => [[]];
        yield '4801 variants' => [array_map(static fn (int $i): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i), range(1, 4801))];
        yield 'not a variant id' => [[self::IN_STOCK, 'not-a-uuid']];
    }

    /**
     * @param list<string> $ids
     */
    #[DataProvider('invalidQueries')]
    public function testInvalidQueryIsRefusedWithoutReading(array $ids): void
    {
        try {
            ($this->handler)(new GetStockLevels($ids));
            self::fail('Expected InvalidStockLevelsQuery.');
        } catch (InvalidStockLevelsQuery) {
        }

        self::assertSame([], $this->stock->manyReads);
    }
}
