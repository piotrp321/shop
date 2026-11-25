<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery\Application;

use App\Delivery\Application\Query\DeliveryOptionView;
use App\Delivery\Application\Query\DeliveryQuoteView;
use App\Delivery\Application\Query\QuoteDelivery;
use App\Delivery\Application\Query\QuoteDeliveryHandler;
use App\Tests\Unit\Delivery\DeliveryMethods;
use App\Tests\Unit\Delivery\InMemoryDeliveryMethodRepository;
use PHPUnit\Framework\TestCase;

/**
 * QuoteDelivery (docs/features/delivery/spec.md, W2): the active methods for the value of the goods after discounts,
 * with the cost of each and the summary the cart shows.
 */
final class QuoteDeliveryHandlerTest extends TestCase
{
    private const string STANDARD = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c8a01';
    private const string EXPRESS = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c8a02';

    private InMemoryDeliveryMethodRepository $methods;

    protected function setUp(): void
    {
        $this->methods = new InMemoryDeliveryMethodRepository();
    }

    public function testEachActiveMethodHasItsCostAndCopiesForTheOrder(): void
    {
        $this->methods->add(
            DeliveryMethods::stored(name: 'Kurier standardowy', price: 1999, freeFrom: 29_900, position: 10, id: self::STANDARD, description: 'Dostawa w 2–3 dni robocze', template: 'https://tracking.example.com/{numer}'),
            DeliveryMethods::stored(name: 'Kurier ekspresowy', price: 3999, freeFrom: 99_900, position: 20, id: self::EXPRESS),
        );

        $quote = $this->quote(25_000);

        self::assertEquals(
            new DeliveryQuoteView(1999, 4900, [
                new DeliveryOptionView(self::STANDARD, 'Kurier standardowy', 'Dostawa w 2–3 dni robocze', 'https://tracking.example.com/{numer}', 1999, 29_900, 4900),
                new DeliveryOptionView(self::EXPRESS, 'Kurier ekspresowy', '', null, 3999, 99_900, 74_900),
            ]),
            $quote,
        );
    }

    public function testFromTheThresholdInclusiveTheMethodIsFreeWithoutAMissingAmount(): void
    {
        $this->methods->add(
            DeliveryMethods::stored(name: 'Kurier standardowy', price: 1999, freeFrom: 29_900, position: 10, id: self::STANDARD),
            DeliveryMethods::stored(name: 'Kurier ekspresowy', price: 3999, freeFrom: 99_900, position: 20, id: self::EXPRESS),
        );

        $quote = $this->quote(29_900);

        self::assertSame([[0, null], [3999, 70_000]], $this->costs($quote));
        self::assertSame(0, $quote->fromGross);
        self::assertNull($quote->missingToFreeGross, 'Nothing is missing once a method is free.');
    }

    public function testOneGroszBelowTheThresholdTheMethodCostsItsPrice(): void
    {
        $this->methods->add(DeliveryMethods::stored(price: 1999, freeFrom: 29_900));

        $quote = $this->quote(29_899);

        self::assertSame([[1999, 1]], $this->costs($quote));
        self::assertSame([1999, 1], [$quote->fromGross, $quote->missingToFreeGross]);
    }

    public function testTheSummaryHasTheCheapestCostAndTheSmallestMissingAmountOfAnyMethod(): void
    {
        $this->methods->add(
            DeliveryMethods::stored(name: 'A', price: 1999, freeFrom: 50_000, position: 1),
            DeliveryMethods::stored(name: 'B', price: 1499, freeFrom: null, position: 2),
            DeliveryMethods::stored(name: 'C', price: 2999, freeFrom: 30_000, position: 3),
        );

        $quote = $this->quote(20_000);

        self::assertSame([[1999, 30_000], [1499, null], [2999, 10_000]], $this->costs($quote));
        self::assertSame(1499, $quote->fromGross);
        self::assertSame(10_000, $quote->missingToFreeGross);
    }

    public function testWithoutThresholdsNothingIsMissing(): void
    {
        $this->methods->add(
            DeliveryMethods::stored(name: 'A', price: 1999),
            DeliveryMethods::stored(name: 'B', price: 2999),
        );

        $quote = $this->quote(10_000);

        self::assertSame([1999, null], [$quote->fromGross, $quote->missingToFreeGross]);
        self::assertSame([[1999, null], [2999, null]], $this->costs($quote));
    }

    public function testAFreeMethodHasNoMissingAmountAndMakesTheSummaryFree(): void
    {
        $this->methods->add(
            DeliveryMethods::stored(name: 'Odbiór', price: 0, freeFrom: 29_900, position: 1),
            DeliveryMethods::stored(name: 'Kurier', price: 1999, freeFrom: 29_900, position: 2),
        );

        $quote = $this->quote(10_000);

        self::assertSame([[0, null], [1999, 19_900]], $this->costs($quote));
        self::assertSame(0, $quote->fromGross);
        self::assertNull($quote->missingToFreeGross);
    }

    public function testWithoutActiveMethodsTheListIsEmptyAndTheSummaryNull(): void
    {
        $this->methods->add(DeliveryMethods::stored(active: false));

        self::assertEquals(new DeliveryQuoteView(null, null, []), $this->quote(10_000));
        self::assertEquals(new DeliveryQuoteView(null, null, []), new QuoteDeliveryHandler(new InMemoryDeliveryMethodRepository())(new QuoteDelivery(0)));
    }

    public function testInactiveMethodsAreLeftOut(): void
    {
        $this->methods->add(
            DeliveryMethods::stored(name: 'Wyłączony', price: 999, active: false, position: 1),
            DeliveryMethods::stored(name: 'Kurier', price: 1999, position: 2, id: self::STANDARD),
        );

        $quote = $this->quote(10_000);

        self::assertSame([self::STANDARD], array_map(static fn (DeliveryOptionView $option): string => $option->methodId, $quote->options));
        self::assertSame(1999, $quote->fromGross);
    }

    public function testTheMethodsAreInTheOrderOfTheirPositionThenOfTheirName(): void
    {
        $this->methods->add(
            DeliveryMethods::stored(name: 'Zeta', position: 20),
            DeliveryMethods::stored(name: 'Łódka', position: 10),
            DeliveryMethods::stored(name: 'beta', position: 10),
            DeliveryMethods::stored(name: 'Lato', position: 10),
            DeliveryMethods::stored(name: 'Alfa', position: 30),
            DeliveryMethods::stored(name: 'Omega', position: 0),
        );

        $quote = $this->quote(10_000);

        self::assertSame(
            ['Omega', 'beta', 'Lato', 'Łódka', 'Zeta', 'Alfa'],
            array_map(static fn (DeliveryOptionView $option): string => $option->name, $quote->options),
        );
    }

    public function testNoGoodsAreQuotedLikeAnyValueBelowTheThreshold(): void
    {
        $this->methods->add(DeliveryMethods::stored(price: 1999, freeFrom: 29_900));

        self::assertSame([[1999, 29_900]], $this->costs($this->quote(0)));
    }

    public function testNegativeGoodsAreALogicError(): void
    {
        $this->methods->add(DeliveryMethods::stored());

        $this->expectException(\InvalidArgumentException::class);

        $this->quote(-1);
    }

    private function quote(int $goodsGross): DeliveryQuoteView
    {
        return new QuoteDeliveryHandler($this->methods)(new QuoteDelivery($goodsGross));
    }

    /**
     * @return list<array{int, int|null}> the cost and the missing amount of each option
     */
    private function costs(DeliveryQuoteView $quote): array
    {
        return array_map(static fn (DeliveryOptionView $option): array => [$option->costGross, $option->missingToFreeGross], $quote->options);
    }
}
