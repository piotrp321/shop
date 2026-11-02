<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Application;

use App\Inventory\Application\Command\CreateEmptyStock;
use App\Inventory\Application\Command\CreateEmptyStockHandler;
use App\Inventory\Application\Command\ReceiveStock;
use App\Inventory\Application\Command\ReceiveStockHandler;
use App\Inventory\Domain\Stock\ProductNotFound;
use App\Tests\Unit\Inventory\InMemoryProductCatalog;
use App\Tests\Unit\Inventory\InMemoryReservationRepository;
use App\Tests\Unit\Inventory\InMemoryStockItemRepository;
use App\Tests\Unit\Inventory\InMemoryTransaction;
use PHPUnit\Framework\TestCase;

final class CreateEmptyStockHandlerTest extends TestCase
{
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    private InMemoryStockItemRepository $stock;
    private InMemoryProductCatalog $catalog;
    private InMemoryTransaction $transaction;
    private CreateEmptyStockHandler $handler;

    protected function setUp(): void
    {
        $this->stock = new InMemoryStockItemRepository();
        $this->catalog = new InMemoryProductCatalog();
        $this->catalog->add(self::A);
        $this->transaction = new InMemoryTransaction($this->stock, new InMemoryReservationRepository());
        $this->handler = new CreateEmptyStockHandler($this->stock, $this->catalog);
    }

    public function testStoresAStockWithNothingOnTheShelfAndNoHistory(): void
    {
        $this->create(self::A);

        $item = $this->stock->stored(self::A);
        self::assertNotNull($item);
        self::assertSame([0, 0, 0], [$item->physical(), $item->reserved(), $item->version()]);
        self::assertSame([], $this->stock->movements);
    }

    public function testLeavesAStockThatAlreadyExistsAsItIs(): void
    {
        $this->stock->store(self::A, 7, 2, version: 3);

        $this->create(self::A);

        $item = $this->stock->stored(self::A);
        self::assertNotNull($item);
        self::assertSame([7, 2, 3], [$item->physical(), $item->reserved(), $item->version()]);
    }

    public function testAReceiptAfterwardsStartsFromTheEmptyStock(): void
    {
        $this->create(self::A);

        $this->transaction->run(fn () => new ReceiveStockHandler($this->stock, $this->catalog)(ReceiveStock::bySeed(self::A, 5, 'seed:'.self::A)));

        $item = $this->stock->stored(self::A);
        self::assertNotNull($item);
        self::assertSame([5, 0, 1], [$item->physical(), $item->reserved(), $item->version()]);
    }

    public function testAProductUnknownToTheCatalogIsRefusedAndNothingIsStored(): void
    {
        try {
            $this->create('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a99');
            self::fail('Expected ProductNotFound.');
        } catch (ProductNotFound) {
            self::assertNull($this->stock->stored('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a99'));
        }
    }

    private function create(string $productId): void
    {
        $this->transaction->run(fn () => ($this->handler)(new CreateEmptyStock($productId)));
    }
}
