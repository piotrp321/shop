<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Query\GetCurrentPrices;
use App\Pricing\Application\Query\GetCurrentPricesHandler;
use App\Pricing\Application\Query\InvalidCurrentPricesQuery;
use App\Pricing\Application\Query\PriceSnapshot;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Shared\Domain\Exception\InvalidInput;
use App\Tests\Unit\Pricing\InMemoryVariantPriceRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GetCurrentPricesHandlerTest extends TestCase
{
    private const string BOOK = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string SHOES = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string OUTDATED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62';
    private const string NO_PRICE = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a63';

    private InMemoryVariantPriceRepository $prices;
    private GetCurrentPricesHandler $handler;

    protected function setUp(): void
    {
        $this->prices = new InMemoryVariantPriceRepository();
        $this->handler = new GetCurrentPricesHandler($this->prices, new PolishVatRates());
        $this->store(self::BOOK, 5490, 'printed_book', 500, 3);
        $this->store(self::SHOES, 12300, 'standard', 2300, 1);
        // Stored at 8%, while the class is at 23% today.
        $this->store(self::OUTDATED, 10800, 'standard', 800, 1);
    }

    public function testReturnsTheStoredBreakdownOfEachVariant(): void
    {
        $snapshots = ($this->handler)(new GetCurrentPrices([self::BOOK, self::SHOES]));

        self::assertEquals([
            new PriceSnapshot(self::BOOK, 'printed_book', 500, 5229, 261, 5490, 'PLN', 3),
            new PriceSnapshot(self::SHOES, 'standard', 2300, 10000, 2300, 12300, 'PLN', 1),
        ], $snapshots);
    }

    public function testSkipsPricesWithAnOutdatedRateAndVariantsWithoutPrice(): void
    {
        $snapshots = ($this->handler)(new GetCurrentPrices([self::OUTDATED, self::NO_PRICE, self::BOOK]));

        self::assertSame([self::BOOK], array_map(static fn (PriceSnapshot $s): string => $s->variantId, $snapshots));
    }

    public function testKeepsTheOrderOfTheRequestAndAnswersADuplicatedIdOnce(): void
    {
        $snapshots = ($this->handler)(new GetCurrentPrices([self::SHOES, self::BOOK, strtoupper(self::SHOES)]));

        self::assertSame([self::SHOES, self::BOOK], array_map(static fn (PriceSnapshot $s): string => $s->variantId, $snapshots));
    }

    public function testAcceptsOneAnd4800Ids(): void
    {
        self::assertCount(1, ($this->handler)(new GetCurrentPrices([self::BOOK])));

        $ids = array_map(static fn (int $n): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n), range(1, 4799));
        $ids[] = self::BOOK;
        self::assertSame([self::BOOK], array_map(static fn (PriceSnapshot $s): string => $s->variantId, ($this->handler)(new GetCurrentPrices($ids))));
    }

    /** @param list<string> $ids */
    #[DataProvider('rejectedIds')]
    public function testRejectsAnEmptyListMoreThan4800IdsAndANonUuid(array $ids): void
    {
        $this->expectException(InvalidCurrentPricesQuery::class);

        ($this->handler)(new GetCurrentPrices($ids));
    }

    public function testTheRejectionIsInvalidInputWithoutTheSubmittedValue(): void
    {
        try {
            ($this->handler)(new GetCurrentPrices(['not-a-secret-uuid']));
            self::fail('Expected InvalidCurrentPricesQuery.');
        } catch (InvalidCurrentPricesQuery $e) {
            self::assertContains(InvalidInput::class, class_implements($e));
            self::assertStringNotContainsString('not-a-secret-uuid', $e->getMessage());
        }
    }

    /** @return iterable<string, array{list<string>}> */
    public static function rejectedIds(): iterable
    {
        yield 'empty' => [[]];
        yield '4801 ids' => [array_map(static fn (int $n): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n), range(1, 4801))];
        yield 'not a uuid' => [[self::BOOK, 'nope']];
    }

    private function store(string $id, int $gross, string $taxClass, int $basisPoints, int $version): void
    {
        $this->prices->store(VariantPrice::reconstitute(
            VariantId::fromString($id),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString($taxClass),
            $version,
            new \DateTimeImmutable(),
        ));
    }
}
