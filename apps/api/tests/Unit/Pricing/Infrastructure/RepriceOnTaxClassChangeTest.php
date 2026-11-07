<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Infrastructure;

use App\Catalog\Application\Event\ProductChanged;
use App\Pricing\Application\TaxReconciliation\RepriceProductPrices;
use App\Pricing\Application\TaxReconciliation\TaxClassChangedWhileRepricing;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\PriceModifiedConcurrently;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Pricing\Infrastructure\Catalog\RepriceOnTaxClassChange;
use App\Tests\Unit\Pricing\ConcurrentlyEditedVariantPriceRepository;
use App\Tests\Unit\Pricing\FixedVatRates;
use App\Tests\Unit\Pricing\InMemoryPersistenceSession;
use App\Tests\Unit\Pricing\InMemoryProductDirectory;
use App\Tests\Unit\Pricing\InMemoryVariantPriceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class RepriceOnTaxClassChangeTest extends TestCase
{
    private const string PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string BLACK = '0192f0c4-7c1a-7c4e-9a6e-000000000011';
    private const string WHITE = '0192f0c4-7c1a-7c4e-9a6e-000000000012';

    private InMemoryVariantPriceRepository $prices;
    private ConcurrentlyEditedVariantPriceRepository $repository;
    private InMemoryProductDirectory $directory;
    private RepriceOnTaxClassChange $consumer;

    protected function setUp(): void
    {
        $rates = new FixedVatRates(['standard' => 2300, 'printed_book' => 500]);
        $session = new InMemoryPersistenceSession();
        $this->prices = new InMemoryVariantPriceRepository();
        $this->repository = new ConcurrentlyEditedVariantPriceRepository($this->prices, $session, $rates);
        $this->directory = new InMemoryProductDirectory()->withVariants(self::PRODUCT, [self::BLACK => 'czarne', self::WHITE => 'białe']);
        $this->consumer = new RepriceOnTaxClassChange(new RepriceProductPrices($this->repository, $this->directory, $rates, $session));
        $this->storePrice(self::BLACK, 5490);
        $this->storePrice(self::WHITE, 12300);
    }

    public function testRepricesThePricesOfTheChangedProductToItsClass(): void
    {
        $this->directory->withTaxClass(self::PRODUCT, 'printed_book');

        ($this->consumer)($this->event());

        self::assertSame(['printed_book', 500, 5490], $this->stored(self::BLACK));
        self::assertSame(['printed_book', 500, 12300], $this->stored(self::WHITE));
    }

    public function testAProductUnknownToCatalogIsHandledWithoutChanges(): void
    {
        ($this->consumer)($this->event('0192f0c4-7c1a-7c4e-9a6e-0000000000ff'));

        self::assertSame(0, $this->prices->writes);
    }

    public function testAClassWithoutRateSendsTheMessageToFailedWithTheClassAndTheProduct(): void
    {
        $this->directory->withTaxClass(self::PRODUCT, 'retired_class');

        try {
            ($this->consumer)($this->event());
            self::fail('Expected UnrecoverableMessageHandlingException.');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertSame('The tax class retired_class of product '.self::PRODUCT.' has no VAT rate; its prices keep their class.', $e->getMessage());
        }
        self::assertSame(0, $this->prices->writes);
        self::assertSame(['standard', 2300, 5490], $this->stored(self::BLACK));
    }

    /**
     * The other prices are saved first; the retry of the message finds them in the class and reprices the rest.
     */
    public function testASecondVersionConflictThrowsForARetry(): void
    {
        $this->directory->withTaxClass(self::PRODUCT, 'printed_book');
        $this->repository->editBeforeSave(self::BLACK, 2);

        try {
            ($this->consumer)($this->event());
            self::fail('Expected PriceModifiedConcurrently.');
        } catch (PriceModifiedConcurrently) {
            // Not unrecoverable: Messenger retries the message.
        }
        self::assertSame(['standard', 2300, 5690], $this->stored(self::BLACK));
        self::assertSame(['printed_book', 500, 12300], $this->stored(self::WHITE));

        ($this->consumer)($this->event());

        self::assertSame(['printed_book', 500, 5690], $this->stored(self::BLACK));
    }

    public function testAClassChangedWhileRepricingIsThrownForARetry(): void
    {
        $this->directory->withTaxClass(self::PRODUCT, 'printed_book')
            ->afterTaxClassRead(function (): void {
                $this->directory->withTaxClass(self::PRODUCT, 'standard');
            });

        $this->expectException(TaxClassChangedWhileRepricing::class);

        ($this->consumer)($this->event());
    }

    public function testAMalformedProductIdSendsTheMessageToFailed(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        ($this->consumer)($this->event('not-a-uuid'));
    }

    private function event(string $productId = self::PRODUCT): ProductChanged
    {
        return new ProductChanged($productId, 'kurtka-puchowa', new \DateTimeImmutable());
    }

    private function storePrice(string $id, int $gross): void
    {
        $this->prices->store(VariantPrice::reconstitute(
            VariantId::fromString($id),
            PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints(2300)),
            TaxClass::fromString('standard'),
            1,
            new \DateTimeImmutable('2026-01-01'),
        ));
    }

    /**
     * @return array{string, int, int} class, rate in basis points and gross
     */
    private function stored(string $id): array
    {
        $price = $this->prices->find(VariantId::fromString($id));
        self::assertNotNull($price);

        return [$price->taxClass()->toString(), $price->breakdown()->rate()->basisPoints(), $price->breakdown()->gross()->amount()];
    }
}
