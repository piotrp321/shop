<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Persistence;

use App\Pricing\Application\Port\PriceHistoryReader;
use App\Pricing\Application\Query\PriceHistoryCursor;
use App\Pricing\Application\Query\PriceHistoryItem;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Infrastructure\Persistence\Doctrine\DbalPriceHistoryReader;
use App\Tests\Factory\PriceHistoryRecordFactory;
use App\Tests\Support\StatementCounter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The price history reader on PostgreSQL (shop_test, rolled back after each test).
 */
final class DbalPriceHistoryReaderTest extends KernelTestCase
{
    use Factories;

    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string OTHER = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';

    private EntityManagerInterface $entityManager;
    private PriceHistoryReader $reader;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->reader = new DbalPriceHistoryReader($entityManager->getConnection());
    }

    public function testReadsTheSnapshotOfAnEntryWithItsActor(): void
    {
        $id = PriceHistoryRecordFactory::store($this->entityManager, [
            'productId' => self::PRODUCT,
            'changedAt' => new \DateTimeImmutable('2026-10-31 12:30:45+02:00'),
            'grossAmount' => 5490, 'netAmount' => 5229, 'vatAmount' => 261, 'vatRateBp' => 500, 'taxClass' => 'printed_book',
            'source' => 'staff', 'actorName' => 'Anna Admin',
        ]);

        $items = $this->page(self::PRODUCT, null, 10);

        self::assertCount(1, $items);
        $item = $items[0];
        self::assertSame([$id, 5490, 5229, 261, 500, 'printed_book', 'staff', 'Anna Admin'], [$item->id, $item->gross, $item->net, $item->vat, $item->vatRate, $item->taxClass, $item->source, $item->actorName]);
        self::assertEquals(new \DateTimeImmutable('2026-10-31 10:30:45+00:00'), $item->changedAt);
    }

    public function testASystemEntryHasNoActorName(): void
    {
        PriceHistoryRecordFactory::store($this->entityManager, ['productId' => self::PRODUCT, 'source' => 'tax_reconciliation', 'actorId' => null, 'actorName' => null]);

        $item = $this->page(self::PRODUCT, null, 10)[0];

        self::assertSame('tax_reconciliation', $item->source);
        self::assertNull($item->actorName);
    }

    public function testReturnsOnlyTheEntriesOfTheProductNewestFirstAndRespectsTheLimit(): void
    {
        $this->entry(self::PRODUCT, '2026-10-01 10:00:00+00:00', 1);
        $this->entry(self::PRODUCT, '2026-10-03 10:00:00+00:00', 3);
        $this->entry(self::OTHER, '2026-10-04 10:00:00+00:00', 9);
        $this->entry(self::PRODUCT, '2026-10-02 10:00:00+00:00', 2);

        self::assertSame([3, 2, 1], $this->grosses($this->page(self::PRODUCT, null, 10)));
        self::assertSame([3, 2], $this->grosses($this->page(self::PRODUCT, null, 2)));
        self::assertSame([], $this->page('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62', null, 10));
    }

    public function testEntriesOfTheSameSecondComeLaterChangeFirstAndKeysetPagingSplitsThemWithoutLoss(): void
    {
        $same = new \DateTimeImmutable('2026-10-01 10:00:00+00:00');
        // Time-ordered ids, as the repository writes them: a smaller id is an earlier change.
        $this->entry(self::PRODUCT, '2026-10-01 09:00:00+00:00', 1);
        $this->entry(self::PRODUCT, $same, 2, '0192f0c4-0000-7000-8000-000000000001');
        $this->entry(self::PRODUCT, $same, 3, '0192f0c4-0000-7000-8000-000000000002');
        $this->entry(self::PRODUCT, $same, 4, '0192f0c4-0000-7000-8000-000000000003');
        $this->entry(self::PRODUCT, '2026-10-02 09:00:00+00:00', 5);

        self::assertSame([5, 4, 3, 2, 1], $this->grosses($this->page(self::PRODUCT, null, 10)));

        $seen = [];
        $after = null;
        do {
            $items = $this->page(self::PRODUCT, $after, 2);
            array_push($seen, ...$this->grosses($items));
            $after = [] === $items ? null : PriceHistoryCursor::after($items[\count($items) - 1]);
        } while ([] !== $items);

        self::assertSame([5, 4, 3, 2, 1], $seen);
    }

    public function testReadsAPageWithOneStatement(): void
    {
        $this->entry(self::PRODUCT, '2026-10-01 10:00:00+00:00', 1);
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);

        $before = $counter->count();
        $this->page(self::PRODUCT, null, 10);

        self::assertSame(1, $counter->count() - $before);
    }

    private function entry(string $productId, string|\DateTimeImmutable $changedAt, int $gross, ?string $id = null): void
    {
        PriceHistoryRecordFactory::store($this->entityManager, [
            'id' => $id ?? (string) Uuid::v7(),
            'productId' => $productId,
            'changedAt' => \is_string($changedAt) ? new \DateTimeImmutable($changedAt) : $changedAt,
            'grossAmount' => $gross,
        ]);
    }

    /**
     * @return list<PriceHistoryItem>
     */
    private function page(string $productId, ?PriceHistoryCursor $after, int $limit): array
    {
        return $this->reader->page(ProductId::fromString($productId), $after, $limit);
    }

    /**
     * @param list<PriceHistoryItem> $items
     *
     * @return list<int>
     */
    private function grosses(array $items): array
    {
        return array_map(static fn (PriceHistoryItem $item): int => $item->gross, $items);
    }
}
