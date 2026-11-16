<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Concurrency;

use App\Inventory\Application\Command\SyncStockRecords;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Infrastructure\Persistence\Doctrine\DbalStockRecords;
use App\Tests\Integration\Inventory\IndependentConnections;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The sync of the stock records of a product waits for the lock of that product, which another sync holds until its
 * transaction ends (here a transaction of its own connection), and it reads the variants of Catalog only once it has
 * the lock; the order of the two is in SyncStockRecordsHandlerTest. The sync of another product does not wait.
 */
final class StockRecordsLockTest extends KernelTestCase
{
    use IndependentConnections;

    private Connection $control;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->control = $this->independentConnection();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($connection instanceof Connection);
        $this->connection = $connection;
        // A sync that waits fails instead of hanging.
        $this->connection->executeStatement("SET lock_timeout = '200ms'");
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('RESET lock_timeout');
        parent::tearDown();
    }

    public function testASyncOfAProductWaitsForTheLockAnotherTransactionHoldsOnIt(): void
    {
        $locked = (string) Uuid::v7();
        $this->control->beginTransaction();
        new DbalStockRecords($this->control)->lockProducts([ProductId::fromString((string) Uuid::v7()), ProductId::fromString($locked)]);

        try {
            $this->commandBus()->dispatch(new SyncStockRecords([$locked]));
            self::fail('Expected the sync to wait for the lock of the product.');
        } catch (HandlerFailedException $e) {
            $refused = $e->getPrevious();
            self::assertInstanceOf(DriverException::class, $refused, $e->getMessage());
            // lock_not_available: the lock_timeout ended the wait.
            self::assertSame('55P03', $refused->getSQLState(), $refused->getMessage());
        }
    }

    public function testASyncOfAnotherProductDoesNotWait(): void
    {
        $this->control->beginTransaction();
        new DbalStockRecords($this->control)->lockProducts([ProductId::fromString((string) Uuid::v7())]);

        $this->commandBus()->dispatch(new SyncStockRecords([(string) Uuid::v7()]));

        $this->addToAssertionCount(1);
    }

    public function testTheLockEndsWithTheTransaction(): void
    {
        $product = (string) Uuid::v7();
        $this->control->beginTransaction();
        new DbalStockRecords($this->control)->lockProducts([ProductId::fromString($product)]);
        $this->control->commit();

        $this->commandBus()->dispatch(new SyncStockRecords([$product]));

        $this->addToAssertionCount(1);
    }

    public function testTheLockNeedsATransaction(): void
    {
        $this->expectException(\LogicException::class);

        new DbalStockRecords($this->control)->lockProducts([ProductId::fromString((string) Uuid::v7())]);
    }

    private function commandBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }
}
