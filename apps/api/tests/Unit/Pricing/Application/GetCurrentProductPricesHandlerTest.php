<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Application;

use App\Pricing\Application\Query\CurrentProductPrice;
use App\Pricing\Application\Query\GetCurrentProductPrices;
use App\Pricing\Application\Query\GetCurrentProductPricesHandler;
use App\Pricing\Application\Query\InvalidCurrentPricesQuery;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Shared\Domain\Exception\InvalidInput;
use App\Tests\Unit\Pricing\InMemoryProductDirectory;
use App\Tests\Unit\Pricing\InMemoryVariantPriceRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The transitional product prices of GET /api/v1/prices (and the cart) until the product offers replace them: a
 * product with exactly one variant has the price of that variant, a product with more variants has none.
 */
final class GetCurrentProductPricesHandlerTest extends TestCase
{
    private const string TENT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string SHOES = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61';
    private const string GEL = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62';
    private const string OUTDATED = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a63';
    private const string UNKNOWN = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a64';

    // Variant ids are never the ids of their products.
    private const string TENT_DEFAULT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01';
    private const string SHOES_40 = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02';
    private const string SHOES_41 = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03';
    private const string GEL_DEFAULT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b04';
    private const string OUTDATED_DEFAULT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b05';

    private InMemoryVariantPriceRepository $prices;
    private InMemoryProductDirectory $directory;
    private GetCurrentProductPricesHandler $handler;

    protected function setUp(): void
    {
        $this->prices = new InMemoryVariantPriceRepository();
        $this->directory = new InMemoryProductDirectory()
            ->withVariants(self::TENT, [self::TENT_DEFAULT => ''])
            ->withVariants(self::SHOES, [self::SHOES_40 => '40', self::SHOES_41 => '41'])
            ->withVariants(self::GEL, [self::GEL_DEFAULT => ''])
            ->withVariants(self::OUTDATED, [self::OUTDATED_DEFAULT => '']);
        $this->handler = new GetCurrentProductPricesHandler($this->directory, $this->prices, new PolishVatRates());
        $this->store(self::TENT_DEFAULT, 69900, 2300);
        $this->store(self::SHOES_40, 34900, 2300);
        $this->store(self::SHOES_41, 34900, 2300);
        // Stored at 8%, while `standard` is at 23% today.
        $this->store(self::OUTDATED_DEFAULT, 10800, 800);
    }

    public function testAProductWithExactlyOneVariantHasThePriceOfThatVariant(): void
    {
        $prices = ($this->handler)(new GetCurrentProductPrices([self::TENT]));

        self::assertEquals([new CurrentProductPrice(self::TENT, 69900, 'PLN')], $prices);
    }

    public function testAProductWithMoreVariantsAnUnknownProductAndOneWithoutACurrentPriceAreLeftOut(): void
    {
        $prices = ($this->handler)(new GetCurrentProductPrices([self::SHOES, self::UNKNOWN, self::GEL, self::OUTDATED, self::TENT]));

        self::assertSame([self::TENT], array_map(static fn (CurrentProductPrice $price): string => $price->productId, $prices));
    }

    public function testKeepsTheOrderOfTheRequestAndAnswersADuplicatedIdOnce(): void
    {
        $this->store(self::GEL_DEFAULT, 1290, 2300);

        $prices = ($this->handler)(new GetCurrentProductPrices([self::GEL, self::TENT, strtoupper(self::GEL)]));

        self::assertSame([self::GEL, self::TENT], array_map(static fn (CurrentProductPrice $price): string => $price->productId, $prices));
    }

    public function testTheVariantsAndThePricesAreReadWithOneCallEach(): void
    {
        ($this->handler)(new GetCurrentProductPrices([self::TENT, self::SHOES, self::GEL]));

        self::assertSame([[self::TENT, self::SHOES, self::GEL]], $this->directory->variantRequests);
        self::assertSame([[self::TENT_DEFAULT, self::GEL_DEFAULT]], $this->prices->manyReads);
    }

    public function testNoProductWithOneVariantReadsNoPrices(): void
    {
        self::assertSame([], ($this->handler)(new GetCurrentProductPrices([self::SHOES, self::UNKNOWN])));

        self::assertSame([], $this->prices->manyReads);
    }

    /** @param list<string> $ids */
    #[DataProvider('rejectedIds')]
    public function testRejectsAnEmptyListMoreThanAHundredIdsAndANonUuidBeforeReadingAnything(array $ids): void
    {
        try {
            ($this->handler)(new GetCurrentProductPrices($ids));
            self::fail('Expected InvalidCurrentPricesQuery.');
        } catch (InvalidCurrentPricesQuery $e) {
            self::assertContains(InvalidInput::class, class_implements($e));
            self::assertStringNotContainsString('nope', $e->getMessage());
        }

        self::assertSame([], $this->directory->variantRequests);
        self::assertSame([], $this->prices->manyReads);
    }

    public function testAcceptsAHundredIds(): void
    {
        $ids = array_map(static fn (int $n): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n), range(1, 99));
        $ids[] = self::TENT;

        self::assertSame([self::TENT], array_map(static fn (CurrentProductPrice $price): string => $price->productId, ($this->handler)(new GetCurrentProductPrices($ids))));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function rejectedIds(): iterable
    {
        yield 'empty' => [[]];
        yield '101 ids' => [array_map(static fn (int $n): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n), range(1, 101))];
        yield 'not a uuid' => [[self::TENT, 'nope']];
    }

    private function store(string $variantId, int $gross, int $basisPoints): void
    {
        $this->prices->store(VariantPrice::reconstitute(
            VariantId::fromString($variantId),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString('standard'),
            1,
            new \DateTimeImmutable(),
        ));
    }
}
