<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing;

use App\Pricing\Application\Query\GetCurrentPrices;
use App\Pricing\Application\Query\InvalidCurrentPricesQuery;
use App\Pricing\Application\Query\PriceSnapshot;
use App\Tests\Support\StatementCounter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * GetCurrentPrices on PostgreSQL, through the real query bus (shop_test, rolled back after each test).
 */
final class GetCurrentPricesTest extends KernelTestCase
{
    private MessageBusInterface $queryBus;
    private Connection $connection;
    private StatementCounter $counter;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->queryBus = $bus;
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $this->counter = $counter;
    }

    public function testHundredProductsAreReadWithOneStatement(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; ++$i) {
            $ids[] = $this->insertPrice(10000 + $i, 'standard', 2300);
        }

        $before = $this->counter->count();
        $snapshots = $this->ask($ids);
        $statements = $this->counter->count() - $before;

        self::assertSame(1, $statements);
        self::assertCount(100, $snapshots);
        self::assertSame($ids, array_map(static fn (PriceSnapshot $s): string => $s->productId, $snapshots));
        self::assertEquals(new PriceSnapshot($ids[0], 'standard', 2300, 8130, 1870, 10000, 'PLN', 1), $snapshots[0]);
    }

    public function testReturnsOnlyPricesAtTheCurrentRateOfTheirClass(): void
    {
        $current = $this->insertPrice(5490, 'printed_book', 500);
        $outdated = $this->insertPrice(5490, 'printed_book', 800);
        $withoutRate = $this->insertPrice(5490, 'unknown_class', 500);
        $withoutPrice = (string) Uuid::v7();

        $snapshots = $this->ask([$outdated, $current, $withoutRate, $withoutPrice]);

        self::assertSame([$current], array_map(static fn (PriceSnapshot $s): string => $s->productId, $snapshots));
        self::assertEquals(new PriceSnapshot($current, 'printed_book', 500, 5229, 261, 5490, 'PLN', 1), $snapshots[0]);
    }

    public function testReadsTheStoredStateNotACachedOne(): void
    {
        $id = $this->insertPrice(5490, 'printed_book', 500);
        self::assertSame(5490, $this->ask([$id])[0]->gross);

        $this->connection->executeStatement(
            'UPDATE pricing_product_price SET gross_amount = 5990, net_amount = 5705, vat_amount = 285, version = 2 WHERE product_id = ?',
            [$id],
        );

        $snapshot = $this->ask([$id])[0];
        self::assertSame(5990, $snapshot->gross);
        self::assertSame(2, $snapshot->version);
    }

    public function testRejectsMoreThanAHundredProductsBeforeReadingAnything(): void
    {
        $ids = array_map(static fn (): string => (string) Uuid::v7(), range(1, 101));

        $before = $this->counter->count();
        try {
            $this->ask($ids);
            self::fail('Expected InvalidCurrentPricesQuery.');
        } catch (HandlerFailedException $failure) {
            self::assertInstanceOf(InvalidCurrentPricesQuery::class, array_values($failure->getWrappedExceptions())[0] ?? null);
        }

        self::assertSame(0, $this->counter->count() - $before);
    }

    private function insertPrice(int $gross, string $taxClass, int $basisPoints): string
    {
        $id = (string) Uuid::v7();
        $divisor = 10000 + $basisPoints;
        $net = intdiv(2 * $gross * 10000 + $divisor, 2 * $divisor);
        $this->connection->executeStatement(
            'INSERT INTO pricing_product_price (product_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, now())',
            [$id, 'PLN', $gross, $net, $gross - $net, $basisPoints, $taxClass],
        );

        return $id;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<PriceSnapshot>
     */
    private function ask(array $ids): array
    {
        $stamp = $this->queryBus->dispatch(new GetCurrentPrices($ids))->last(HandledStamp::class);
        self::assertInstanceOf(HandledStamp::class, $stamp);
        $result = $stamp->getResult();
        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(PriceSnapshot::class, $result);

        return array_values($result);
    }
}
