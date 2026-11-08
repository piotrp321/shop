<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing;

use App\Pricing\Application\Query\GetCurrentPrices;
use App\Pricing\Application\Query\InvalidCurrentPricesQuery;
use App\Pricing\Application\Query\PriceSnapshot;
use App\Pricing\Domain\Promotion\PromotionPeriod;
use App\Tests\Factory\PriceHistoryRecordFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
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
    private EntityManagerInterface $entityManager;
    private StatementCounter $counter;

    protected function setUp(): void
    {
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->queryBus = $bus;
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $this->counter = $counter;
    }

    /**
     * 48 products of 100 variants: the most a page of the store list can ask for. One statement for the prices, one
     * for the active promotions.
     */
    public function test4800VariantsAreReadWithTwoStatements(): void
    {
        $ids = array_map(
            static fn (mixed $id): string => \is_string($id) ? $id : throw new \LogicException('Expected a UUID.'),
            $this->connection->fetchFirstColumn('SELECT gen_random_uuid()::text FROM generate_series(1, 4800)'),
        );
        $this->connection->executeStatement(
            "INSERT INTO pricing_variant_price (variant_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version, updated_at)
             SELECT CAST(id AS uuid), 'PLN', 10000, 8130, 1870, 2300, 'standard', 1, NOW() FROM unnest(CAST(:ids AS text[])) AS id",
            ['ids' => '{'.implode(',', $ids).'}'],
        );

        $before = $this->counter->count();
        $snapshots = $this->ask($ids);
        $statements = $this->counter->count() - $before;

        self::assertSame(2, $statements);
        self::assertCount(4800, $snapshots);
        self::assertSame($ids, array_map(static fn (PriceSnapshot $s): string => $s->variantId, $snapshots));
        self::assertEquals(new PriceSnapshot($ids[0], 'standard', 2300, 8130, 1870, 10000, 'PLN', 1, 10000, null), $snapshots[0]);
    }

    /**
     * Variants of two active promotions: their promotional prices, and with the flag their Omnibus prices from one
     * statement for the history and one for the earlier promotions of all of them.
     */
    public function testPromotedVariantsWithTheirOmnibusPricesInFourStatements(): void
    {
        $now = new \DateTimeImmutable()->setTimezone(new \DateTimeZone('UTC'));
        $book = $this->insertPrice(5490, 'printed_book', 500);
        $tent = $this->insertPrice(60000, 'standard', 2300);
        $regular = $this->insertPrice(12300, 'standard', 2300);
        $this->insertHistory($book, $now->modify('-60 days'), 6000);
        $this->insertHistory($book, $now->modify('-10 days'), 5490);
        $this->insertHistory($tent, $now->modify('-60 days'), 60000);
        $autumn = $this->insertPromotion('Jesień', 20, $now->modify('-1 day'), $now->modify('+1 day'), [$book]);
        $this->insertPromotion('Namioty', 10, $now->modify('-2 hours'), $now->modify('+2 days'), [$tent]);
        $this->insertPromotion('Wcześniej', 50, $now->modify('-20 days'), $now->modify('-15 days'), [$tent]);

        $before = $this->counter->count();
        $snapshots = $this->ask([$book, $tent, $regular], withLowestPriorPrice: true);
        $statements = $this->counter->count() - $before;

        self::assertSame(4, $statements);
        self::assertSame([4392, 54000, 12300], array_map(static fn (PriceSnapshot $s): int => $s->gross, $snapshots));
        self::assertSame([5490, 60000, 12300], array_map(static fn (PriceSnapshot $s): int => $s->regularGross, $snapshots));
        self::assertSame([[4183, 209], [43902, 10098], [10000, 2300]], array_map(static fn (PriceSnapshot $s): array => [$s->net, $s->vat], $snapshots));
        self::assertSame([5490, 30000, null], array_map(static fn (PriceSnapshot $s): ?int => $s->promotion?->lowestPriorGross, $snapshots));
        self::assertSame($autumn, $snapshots[0]->promotion?->promotionId);
        self::assertSame(['Jesień', 20], [$snapshots[0]->promotion->name, $snapshots[0]->promotion->percentOff]);
        self::assertSame(
            PromotionPeriod::wholeSecond($now->modify('+1 day'))->format(\DATE_ATOM),
            $snapshots[0]->promotion->endsAt->format(\DATE_ATOM),
        );

        $before = $this->counter->count();
        $withoutFlag = $this->ask([$book, $tent, $regular]);
        self::assertSame(2, $this->counter->count() - $before);
        self::assertSame([null, null], [$withoutFlag[0]->promotion?->lowestPriorGross, $withoutFlag[1]->promotion?->lowestPriorGross]);
        self::assertSame([4392, 54000, 12300], array_map(static fn (PriceSnapshot $s): int => $s->gross, $withoutFlag));
    }

    public function testReturnsOnlyPricesAtTheCurrentRateOfTheirClass(): void
    {
        $current = $this->insertPrice(5490, 'printed_book', 500);
        $outdated = $this->insertPrice(5490, 'printed_book', 800);
        $withoutRate = $this->insertPrice(5490, 'unknown_class', 500);
        $withoutPrice = (string) Uuid::v7();

        $snapshots = $this->ask([$outdated, $current, $withoutRate, $withoutPrice]);

        self::assertSame([$current], array_map(static fn (PriceSnapshot $s): string => $s->variantId, $snapshots));
        self::assertEquals(new PriceSnapshot($current, 'printed_book', 500, 5229, 261, 5490, 'PLN', 1, 5490, null), $snapshots[0]);
    }

    public function testReadsTheStoredStateNotACachedOne(): void
    {
        $id = $this->insertPrice(5490, 'printed_book', 500);
        self::assertSame(5490, $this->ask([$id])[0]->gross);

        $this->connection->executeStatement(
            'UPDATE pricing_variant_price SET gross_amount = 5990, net_amount = 5705, vat_amount = 285, version = 2 WHERE variant_id = ?',
            [$id],
        );

        $snapshot = $this->ask([$id])[0];
        self::assertSame(5990, $snapshot->gross);
        self::assertSame(2, $snapshot->version);
    }

    public function testRejectsMoreThan4800VariantsBeforeReadingAnything(): void
    {
        $ids = array_map(static fn (): string => (string) Uuid::v7(), range(1, 4801));

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
        return VariantPriceRecordFactory::store($this->entityManager, ['gross' => $gross, 'taxClass' => $taxClass, 'vatRateBp' => $basisPoints]);
    }

    private function insertHistory(string $variantId, \DateTimeImmutable $changedAt, int $gross): void
    {
        PriceHistoryRecordFactory::store($this->entityManager, ['variantId' => $variantId, 'changedAt' => PromotionPeriod::wholeSecond($changedAt), 'grossAmount' => $gross]);
    }

    /**
     * @param list<string> $variantIds
     */
    private function insertPromotion(string $name, int $percentOff, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt, array $variantIds): string
    {
        $id = (string) Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO pricing_promotion (id, name, percent_off, starts_at, ends_at, created_at, updated_at, version) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 1)',
            [$id, $name, $percentOff, PromotionPeriod::wholeSecond($startsAt)->format('Y-m-d H:i:sP'), PromotionPeriod::wholeSecond($endsAt)->format('Y-m-d H:i:sP')],
        );
        foreach ($variantIds as $variantId) {
            $this->connection->executeStatement('INSERT INTO pricing_promotion_variant (promotion_id, variant_id) VALUES (?, ?)', [$id, $variantId]);
        }

        return $id;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<PriceSnapshot>
     */
    private function ask(array $ids, bool $withLowestPriorPrice = false): array
    {
        $stamp = $this->queryBus->dispatch(new GetCurrentPrices($ids, $withLowestPriorPrice))->last(HandledStamp::class);
        self::assertInstanceOf(HandledStamp::class, $stamp);
        $result = $stamp->getResult();
        self::assertIsArray($result);
        self::assertContainsOnlyInstancesOf(PriceSnapshot::class, $result);

        return array_values($result);
    }
}
