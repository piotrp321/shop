<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Domain;

use App\Ordering\Domain\AccessToken;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\InvalidOrder;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderDelivery;
use App\Ordering\Domain\OrderDeliveryShare;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderLine;
use App\Ordering\Domain\OrderNumber;
use App\Ordering\Domain\OrderOwner;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Domain\OrderTotals;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\PaymentResult;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\ProductId;
use App\Ordering\Domain\RequestFingerprint;
use App\Ordering\Domain\ShippingAddress;
use App\Ordering\Domain\StaffActor;
use App\Ordering\Domain\VariantId;
use App\Tests\Unit\Ordering\OrderExamples;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The delivery of an order (docs/features/delivery/spec.md, W6, P13, P19): the copy of the method, its cost split
 * over the VAT rates of the goods, the totals with it, the payment and the refund of the total with it, and an order
 * stored without one (before the delivery, P17) that goes through every transition as before.
 */
final class OrderDeliveryTest extends TestCase
{
    private const string METHOD = '0192f0c4-7c1a-7c4e-9a6e-0000000000d1';
    private const string TEMPLATE = 'https://tracking.example.com/{numer}';
    private const string PRODUCT = 'c0ffee00-7c1a-7c4e-9a6e-000000000042';
    private const string PAYMENT = '0192f0c4-7c1a-7c4e-9a6e-0000000000b1';
    private const string NOW = '2026-11-05 12:00:00+00:00';
    /** Two units at 50,00 zł at 23 % and one at 50,00 zł at 8 %: the example of W3 (10 000 and 5 000). */
    private const int GOODS = 15_000;
    private const int COST = 1_999;

    public function testADeliveryKeepsTheCopyOfTheMethodItsCostAndItsShares(): void
    {
        $delivery = self::paidDelivery();

        self::assertSame(self::METHOD, $delivery->methodId());
        self::assertSame('Kurier standardowy', $delivery->methodName());
        self::assertSame(self::TEMPLATE, $delivery->trackingUrlTemplate());
        self::assertSame([self::COST, 1_084 + 617, 249 + 49], [$delivery->gross()->amount(), $delivery->net()->amount(), $delivery->vat()->amount()]);
        self::assertSame(
            [[2300, 1_333, 1_084, 249], [800, 666, 617, 49]],
            array_map(static fn (OrderDeliveryShare $share): array => [$share->rateBp(), $share->gross()->amount(), $share->net()->amount(), $share->vat()->amount()], $delivery->shares()),
        );
    }

    public function testAFreeDeliveryHasNoSharesAndCostsNothing(): void
    {
        $delivery = OrderDelivery::of(self::METHOD, 'Kurier standardowy', null, 0, [], Currency::PLN);

        self::assertSame([0, 0, 0], [$delivery->gross()->amount(), $delivery->net()->amount(), $delivery->vat()->amount()]);
        self::assertSame([], $delivery->shares());
        self::assertNull($delivery->trackingUrlTemplate());
    }

    public function testTheMethodIdIsKeptInLowerCase(): void
    {
        self::assertSame(self::METHOD, OrderDelivery::of(strtoupper(self::METHOD), 'Kurier', null, 0, [], Currency::PLN)->methodId());
    }

    /**
     * @return iterable<string, array{int, list<array{int, int, int, int}>}> the cost and the shares (rate, gross, net, VAT)
     */
    public static function sharesThatAreNotTheCost(): iterable
    {
        yield 'a grosz short' => [self::COST, [[2300, 1_333, 1_084, 249], [800, 665, 616, 49]]];
        yield 'a grosz over' => [self::COST, [[2300, 1_334, 1_085, 249], [800, 666, 617, 49]]];
        yield 'a cost without shares' => [self::COST, []];
        yield 'shares of a free delivery' => [0, [[2300, 1, 1, 0]]];
        yield 'two shares of one rate' => [2, [[2300, 1, 1, 0], [2300, 1, 1, 0]]];
    }

    /**
     * @param list<array{int, int, int, int}> $shares
     */
    #[DataProvider('sharesThatAreNotTheCost')]
    public function testSharesThatAreNotTheCostAreRefused(int $cost, array $shares): void
    {
        $this->expectException(InvalidOrder::class);

        OrderDelivery::of(self::METHOD, 'Kurier', null, $cost, array_map(static fn (array $share): OrderDeliveryShare => OrderDeliveryShare::of(...$share, currency: Currency::PLN), $shares), Currency::PLN);
    }

    /**
     * @return iterable<string, array{int, int, int, int}> the rate, the gross, the net and the VAT of a share
     */
    public static function invalidShares(): iterable
    {
        yield 'a share of 0' => [2300, 0, 0, 0];
        yield 'net + VAT less than the gross' => [2300, 100, 80, 19];
        yield 'net + VAT more than the gross' => [2300, 100, 82, 19];
        yield 'a negative VAT' => [2300, 100, 101, -1];
        yield 'a rate below 0' => [-1, 100, 100, 0];
        yield 'a rate above 100 %' => [10_001, 100, 100, 0];
    }

    #[DataProvider('invalidShares')]
    public function testAnInvalidShareIsRefused(int $rateBp, int $gross, int $net, int $vat): void
    {
        $this->expectException(InvalidOrder::class);

        OrderDeliveryShare::of($rateBp, $gross, $net, $vat, Currency::PLN);
    }

    /**
     * @return iterable<string, array{string, string, ?string}> the method id, the name and the template
     */
    public static function invalidCopies(): iterable
    {
        yield 'a method id that is no UUID' => ['kurier', 'Kurier', null];
        yield 'an empty name' => [self::METHOD, '', null];
        yield 'a name of 101 characters' => [self::METHOD, str_repeat('ł', 101), null];
        yield 'an empty template' => [self::METHOD, 'Kurier', ''];
        yield 'a template of 501 characters' => [self::METHOD, 'Kurier', 'https://t.example/{numer}'.str_repeat('a', 501 - 25)];
    }

    #[DataProvider('invalidCopies')]
    public function testACopyOfTheMethodThatTheColumnsCannotKeepIsRefused(string $methodId, string $name, ?string $template): void
    {
        $this->expectException(InvalidOrder::class);

        OrderDelivery::of($methodId, $name, $template, 0, [], Currency::PLN);
    }

    public function testAMethodNameOfOneHundredCharactersAndATemplateOfFiveHundredAreKept(): void
    {
        $template = 'https://t.example/{numer}'.str_repeat('a', 500 - 25);
        $delivery = OrderDelivery::of(self::METHOD, str_repeat('ł', 100), $template, 0, [], Currency::PLN);

        self::assertSame(str_repeat('ł', 100), $delivery->methodName());
        self::assertSame($template, $delivery->trackingUrlTemplate());
    }

    public function testTheTotalsIncludeTheDeliveryAndGoodsGrossIsTheSumOfTheLines(): void
    {
        $totals = OrderTotals::of(self::lines(), self::paidDelivery());

        self::assertSame(self::GOODS, $totals->goodsGross()->amount());
        self::assertSame(self::GOODS + self::COST, $totals->gross()->amount());
        self::assertSame(8_130 + 4_630 + 1_701, $totals->net()->amount());
        self::assertSame(1_870 + 370 + 298, $totals->vat()->amount());
        self::assertSame($totals->gross()->amount(), $totals->net()->amount() + $totals->vat()->amount());
    }

    public function testTheTotalsOfAnOrderWithoutADeliveryOrWithAFreeOneAreTheSumsOfTheLines(): void
    {
        foreach ([null, OrderDelivery::of(self::METHOD, 'Kurier', null, 0, [], Currency::PLN)] as $delivery) {
            $totals = OrderTotals::of(self::lines(), $delivery);

            self::assertSame([self::GOODS, self::GOODS, 12_760, 2_240], [$totals->goodsGross()->amount(), $totals->gross()->amount(), $totals->net()->amount(), $totals->vat()->amount()]);
        }
    }

    public function testAnOrderIsPlacedWithItsDeliveryAndItsTotalsIncludeIt(): void
    {
        $order = self::place(self::paidDelivery());

        self::assertSame(self::METHOD, $order->delivery()?->methodId());
        self::assertSame(self::GOODS + self::COST, $order->totals()->gross()->amount());
        self::assertSame(self::GOODS, $order->totals()->goodsGross()->amount());
    }

    public function testOrderPlaceRequiresADelivery(): void
    {
        $parameter = null;
        foreach (new \ReflectionMethod(Order::class, 'place')->getParameters() as $candidate) {
            if ('delivery' === $candidate->getName()) {
                $parameter = $candidate;
            }
        }

        self::assertNotNull($parameter, 'Order::place takes the delivery');
        $type = $parameter->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $type);
        self::assertSame(OrderDelivery::class, $type->getName());
        self::assertFalse($type->allowsNull(), 'every new order has a method (P19)');
    }

    public function testAShareAtARateNoLineHasIsRefused(): void
    {
        $this->expectException(InvalidOrder::class);

        self::place(OrderDelivery::of(self::METHOD, 'Kurier', null, self::COST, [
            OrderDeliveryShare::of(2300, 1_333, 1_084, 249, Currency::PLN),
            OrderDeliveryShare::of(500, 666, 634, 32, Currency::PLN),
        ], Currency::PLN));
    }

    public function testAPaymentPaysTheTotalWithTheDeliveryOnly(): void
    {
        $order = self::place(self::paidDelivery());
        $now = new \DateTimeImmutable(self::NOW);

        try {
            $order->markPaid(PaymentId::fromString(self::PAYMENT), self::GOODS, 'PLN', $now, $now);
            self::fail('Expected InvalidOrder: the goods without the delivery are not the total.');
        } catch (InvalidOrder) {
            self::assertSame(OrderStatus::New, $order->status());
        }

        self::assertSame(PaymentResult::Paid, $order->markPaid(PaymentId::fromString(self::PAYMENT), self::GOODS + self::COST, 'PLN', $now, $now));
    }

    public function testACancellationByStaffRefundsTheTotalWithTheDelivery(): void
    {
        $order = $this->paid(self::place(self::paidDelivery()));

        $order->cancel(self::staff(), 'Brak towaru', new \DateTimeImmutable(self::NOW)->modify('+1 hour'));

        self::assertSame(self::GOODS + self::COST, $order->refund()?->amount()->amount());
    }

    public function testAReturnRefundsTheTotalWithTheDelivery(): void
    {
        $order = $this->paid(self::place(self::paidDelivery()));
        $later = new \DateTimeImmutable(self::NOW)->modify('+1 day');
        $order->ship(self::staff(), null, null, $later);

        $order->registerReturn(self::staff(), 'Zwrot', $later->modify('+1 day'));

        self::assertSame(self::GOODS + self::COST, $order->refund()?->amount()->amount());
    }

    public function testAnOrderStoredWithoutADeliveryGoesThroughEveryTransitionAsBefore(): void
    {
        $stored = static fn (): Order => OrderExamples::copy(self::place(OrderDelivery::of(self::METHOD, 'Kurier', null, 0, [], Currency::PLN)), withoutDelivery: true);
        $later = new \DateTimeImmutable(self::NOW)->modify('+1 day');

        $returned = $stored();
        self::assertNull($returned->delivery());
        self::assertSame([self::GOODS, self::GOODS], [$returned->totals()->gross()->amount(), $returned->totals()->goodsGross()->amount()]);
        $this->paid($returned);
        $returned->ship(self::staff(), null, null, $later);
        $returned->deliver(self::staff(), null, $later->modify('+1 hour'));
        $returned->registerReturn(self::staff(), 'Zwrot', $later->modify('+2 hours'));
        self::assertSame(OrderStatus::Returned, $returned->status());
        self::assertSame(self::GOODS, $returned->refund()?->amount()->amount());

        $cancelled = $this->paid($stored());
        $cancelled->cancel(self::staff(), 'Brak towaru', $later);
        self::assertSame(self::GOODS, $cancelled->refund()?->amount()->amount());

        $expired = $stored();
        $expired->expireUnpaid($expired->expiryDueAt());
        self::assertSame(OrderStatus::Cancelled, $expired->status());
    }

    private function paid(Order $order): Order
    {
        $now = new \DateTimeImmutable(self::NOW);
        self::assertSame(PaymentResult::Paid, $order->markPaid(PaymentId::fromString(self::PAYMENT), $order->totals()->gross()->amount(), 'PLN', $now, $now));

        return $order;
    }

    /**
     * The delivery of the example of W3: 19,99 zł over 10 000 at 23 % and 5 000 at 8 %.
     */
    private static function paidDelivery(): OrderDelivery
    {
        return OrderDelivery::of(self::METHOD, 'Kurier standardowy', self::TEMPLATE, self::COST, [
            OrderDeliveryShare::of(2300, 1_333, 1_084, 249, Currency::PLN),
            OrderDeliveryShare::of(800, 666, 617, 49, Currency::PLN),
        ], Currency::PLN);
    }

    private static function place(OrderDelivery $delivery): Order
    {
        $address = ShippingAddress::inPoland('Długa 1', '00-950', 'Warszawa');
        $contact = Contact::of(EmailAddress::fromString('jan@example.com'), 'Jan Kowalski', '600100200');

        $order = Order::place(
            OrderId::fromString(OrderExamples::ORDER),
            OrderNumber::fromSequence(100001),
            OrderOwner::guest(),
            AccessToken::fromString(OrderExamples::TOKEN),
            $contact,
            $address,
            self::lines(),
            null,
            $delivery,
            IdempotencyKey::fromString('key-1'),
            RequestFingerprint::of('guest:cart', $contact, $address, self::GOODS + $delivery->gross()->amount()),
            new \DateTimeImmutable(self::NOW),
        );
        $order->releaseEvents();

        return $order;
    }

    /**
     * @return list<OrderLine> 2 × 50,00 zł at 23 % (net 8 130, VAT 1 870) and 1 × 50,00 zł at 8 % (net 4 630, VAT 370)
     */
    private static function lines(): array
    {
        $standard = PriceCopy::of(4_065, 935, 5_000, 2300, 'standard', Currency::PLN);
        $reduced = PriceCopy::of(4_630, 370, 5_000, 800, 'reduced', Currency::PLN);
        $product = ProductId::fromString(self::PRODUCT);

        return [
            OrderLine::of(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-000000000001'), $product, 'Buty Asfalt', '42', 2, $standard, OrderExamples::undiscounted($standard, 2), null),
            OrderLine::of(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-000000000002'), $product, 'Mapa Gorce', '', 1, $reduced, OrderExamples::undiscounted($reduced, 1), null),
        ];
    }

    private static function staff(): StaffActor
    {
        return StaffActor::of('kc-staff-1', 'magazyn');
    }
}
