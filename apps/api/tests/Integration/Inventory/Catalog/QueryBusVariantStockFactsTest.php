<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Catalog;

use App\Inventory\Application\Query\InvalidStockLevelsQuery;
use App\Inventory\Infrastructure\Catalog\QueryBusVariantStockFacts;
use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Catalog's facts of stock (port VariantStockFacts) through Inventory's GetStockLevels on the real query bus and
 * shop_test, rolled back after each test.
 */
final class QueryBusVariantStockFactsTest extends KernelTestCase
{
    private Connection $connection;
    private QueryBusVariantStockFacts $facts;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $queryBus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $queryBus);
        $this->facts = new QueryBusVariantStockFacts($queryBus);
    }

    public function testVariantsWithPhysicalOrReservedStockAreInUseAndVariantsWithoutStockOrARecordAreNot(): void
    {
        $physical = StockFixture::stock($this->connection, 5);
        $reserved = StockFixture::stock($this->connection, 2, reserved: 2);
        $empty = StockFixture::stock($this->connection, 0);
        $withoutRecord = (string) Uuid::v7();

        $inUse = $this->facts->inUse([$withoutRecord, $empty, $reserved, $physical]);

        self::assertSame([$reserved, $physical], $inUse);
    }

    public function testNoVariantsAskNothing(): void
    {
        self::assertSame([], $this->facts->inUse([]));
    }

    public function testAQueryRefusedByInventoryReachesTheCallerUnwrapped(): void
    {
        $this->expectException(InvalidStockLevelsQuery::class);

        $this->facts->inUse(['not-a-uuid']);
    }
}
