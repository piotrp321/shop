<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Query\GetPriceHistory;
use App\Pricing\Application\Query\GetPriceHistoryHandler;
use App\Pricing\Application\Query\InvalidPriceHistoryCursor;
use App\Pricing\Application\Query\PriceHistoryItem;
use App\Pricing\Domain\Price\ProductNotFound;
use App\Tests\Unit\Pricing\InMemoryPriceHistoryReader;
use App\Tests\Unit\Pricing\InMemoryProductTaxClasses;
use PHPUnit\Framework\TestCase;

final class GetPriceHistoryHandlerTest extends TestCase
{
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string OTHER = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string UNKNOWN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62';

    private InMemoryPriceHistoryReader $history;
    private InMemoryProductTaxClasses $taxClasses;

    protected function setUp(): void
    {
        $this->history = new InMemoryPriceHistoryReader();
        $this->taxClasses = new InMemoryProductTaxClasses([self::PRODUCT => 'printed_book', self::OTHER => 'standard']);
    }

    public function testAnUnknownProductIsNotFoundAndNothingIsRead(): void
    {
        try {
            $this->handler()(new GetPriceHistory(self::UNKNOWN, null, 25));
            self::fail('Expected ProductNotFound.');
        } catch (ProductNotFound) {
            self::assertSame([], $this->history->requests);
        }
    }

    public function testAProductWithoutHistoryHasAnEmptyPage(): void
    {
        $page = $this->handler()(new GetPriceHistory(self::PRODUCT, null, 25));

        self::assertSame([], $page->items);
        self::assertNull($page->next);
    }

    public function testListsTheEntriesOfTheProductNewestFirstWithoutThoseOfOtherProducts(): void
    {
        $this->entry(self::PRODUCT, 1, '2026-10-01 10:00:00+00:00', 5490, 'seed');
        $this->entry(self::PRODUCT, 3, '2026-10-03 10:00:00+00:00', 5990, 'tax_reconciliation');
        $this->entry(self::PRODUCT, 2, '2026-10-02 10:00:00+00:00', 5790, 'staff', 'Jan Kowalski');
        $this->entry(self::OTHER, 4, '2026-10-04 10:00:00+00:00', 1000, 'staff', 'Ewa');

        $page = $this->handler()(new GetPriceHistory(self::PRODUCT, null, 25));

        self::assertNull($page->next);
        self::assertSame([3, 2, 1], array_map(static fn (PriceHistoryItem $i): int => (int) substr($i->id, -12), $page->items));
        $staff = $page->items[1];
        self::assertSame([5790, 'staff', 'Jan Kowalski'], [$staff->gross, $staff->source, $staff->actorName]);
        self::assertNull($page->items[0]->actorName);
    }

    public function testEntriesOfTheSameSecondComeNewestFirstByTheirTimeOrderedId(): void
    {
        $this->entry(self::PRODUCT, 1, '2026-10-01 10:00:00+00:00', 100, 'staff', 'Jan');
        $this->entry(self::PRODUCT, 2, '2026-10-01 10:00:00+00:00', 200, 'staff', 'Jan');

        $page = $this->handler()(new GetPriceHistory(self::PRODUCT, null, 25));

        self::assertSame([200, 100], array_map(static fn (PriceHistoryItem $i): int => $i->gross, $page->items));
    }

    public function testPagesByKeysetWithoutRepeatingOrSkippingEntries(): void
    {
        foreach (range(1, 5) as $n) {
            $this->entry(self::PRODUCT, $n, \sprintf('2026-10-0%d 10:00:00+00:00', $n), 1000 * $n, 'staff', 'Jan');
        }

        $first = $this->handler()(new GetPriceHistory(self::PRODUCT, null, 2));
        self::assertNotNull($first->next);
        $second = $this->handler()(new GetPriceHistory(self::PRODUCT, $first->next, 2));
        self::assertNotNull($second->next);
        $third = $this->handler()(new GetPriceHistory(self::PRODUCT, $second->next, 2));

        self::assertSame([5000, 4000], array_map(static fn (PriceHistoryItem $i): int => $i->gross, $first->items));
        self::assertSame([3000, 2000], array_map(static fn (PriceHistoryItem $i): int => $i->gross, $second->items));
        self::assertSame([1000], array_map(static fn (PriceHistoryItem $i): int => $i->gross, $third->items));
        self::assertNull($third->next);
    }

    public function testAFullLastPageHasNoNextCursor(): void
    {
        $this->entry(self::PRODUCT, 1, '2026-10-01 10:00:00+00:00', 100, 'seed');
        $this->entry(self::PRODUCT, 2, '2026-10-02 10:00:00+00:00', 200, 'seed');

        $page = $this->handler()(new GetPriceHistory(self::PRODUCT, null, 2));

        self::assertCount(2, $page->items);
        self::assertNull($page->next);
    }

    public function testAsksTheReaderForOneEntryMoreThanThePageToKnowWhetherThereIsANextPage(): void
    {
        $this->handler()(new GetPriceHistory(self::PRODUCT, null, 10));

        self::assertSame([[self::PRODUCT, null, 11]], $this->history->requests);
    }

    public function testACursorNobodyIssuedIsRejectedBeforeAnythingIsRead(): void
    {
        $this->expectException(InvalidPriceHistoryCursor::class);

        try {
            $this->handler()(new GetPriceHistory(self::PRODUCT, 'garbage!', 25));
        } finally {
            self::assertSame([], $this->history->requests);
            self::assertSame([], $this->taxClasses->asked);
        }
    }

    private function handler(): GetPriceHistoryHandler
    {
        return new GetPriceHistoryHandler($this->taxClasses, $this->history);
    }

    private function entry(string $productId, int $n, string $changedAt, int $gross, string $source, ?string $actorName = null): void
    {
        $this->history->add($productId, new PriceHistoryItem(\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n), new \DateTimeImmutable($changedAt), $gross, $gross - 10, 10, 500, 'printed_book', $source, $actorName));
    }
}
