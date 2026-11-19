<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering\Application;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\Command\PlaceOrderHandler;
use App\Ordering\Application\UnpaidOrderLimit;
use App\Ordering\Application\View\OrderLineView;
use App\Ordering\Domain\CartEmpty;
use App\Ordering\Domain\CartNotOrderable;
use App\Ordering\Domain\DiscountCodeRejected;
use App\Ordering\Domain\GuestOrderTooLarge;
use App\Ordering\Domain\IdempotencyKeyReused;
use App\Ordering\Domain\InsufficientStockForOrder;
use App\Ordering\Domain\InvalidOrderInput;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderDiscountKind;
use App\Ordering\Domain\OrderPlaced;
use App\Ordering\Domain\OrderTotalChanged;
use App\Ordering\Domain\PromotionCopy;
use App\Ordering\Domain\TooManyUnpaidOrders;
use App\Tests\Unit\Ordering\CartGone;
use App\Tests\Unit\Ordering\FixedOrderingClock;
use App\Tests\Unit\Ordering\InMemoryCustomerSummaries;
use App\Tests\Unit\Ordering\InMemoryDiscountCodeRedemptions;
use App\Tests\Unit\Ordering\InMemoryOrderCart;
use App\Tests\Unit\Ordering\InMemoryOrderCatalog;
use App\Tests\Unit\Ordering\InMemoryOrderPrices;
use App\Tests\Unit\Ordering\InMemoryOrders;
use App\Tests\Unit\Ordering\InMemoryStockReservations;
use App\Tests\Unit\Ordering\InMemoryUnpaidOrderClaims;
use App\Tests\Unit\Ordering\InMemoryUnpaidOrderExpiry;
use App\Tests\Unit\Ordering\Journal;
use App\Tests\Unit\Ordering\JournalingEventPublisher;
use App\Tests\Unit\Ordering\SequenceOrderNumbers;
use PHPUnit\Framework\TestCase;

/**
 * PlaceOrder with in-memory ports: the steps 0 to 9 of the specification ("Złożenie zamówienia"). The transaction
 * that rolls back a failed placement is the bus's, so these tests check what the handler does and in which order.
 */
final class PlaceOrderHandlerTest extends TestCase
{
    private const string CART = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';
    private const string OTHER_CART = '7a1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';
    private const string CUSTOMER = 'kc-sub-1';
    private const string SHOES = '0192f0c4-7c1a-7c4e-9a6e-00000000000a';
    private const string CAP = '0192f0c4-7c1a-7c4e-9a6e-00000000000b';
    private const string MAP = '0192f0c4-7c1a-7c4e-9a6e-00000000000c';
    private const string SHOES_PRODUCT = 'c0ffee00-7c1a-7c4e-9a6e-00000000000a';
    private const string CAP_PRODUCT = 'c0ffee00-7c1a-7c4e-9a6e-00000000000b';
    private const string MAP_PRODUCT = 'c0ffee00-7c1a-7c4e-9a6e-00000000000c';
    /** 2 × 36 900 + 1 × 2 000 */
    private const int TOTAL = 75_800;
    private const string CODE_ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6d00';
    /** 10 % of TOTAL */
    private const int DISCOUNT = 7_580;
    private const string TOO_LARGE = 'Zamówienie bez konta może mieć najwyżej 10 różnych produktów, po 10 sztuk każdego. Zmniejsz ilości w koszyku.';
    private const string INVALID = 'tag:piotrp321.github.io,2026:shop/problems/discount-code-invalid';
    private const string USED_UP = 'tag:piotrp321.github.io,2026:shop/problems/discount-code-used-up';
    private const string ADDRESS = '203.0.113.7';
    private const int PER_ADDRESS = 5;
    private const int PER_IDENTITY = 3;
    private const string TOO_MANY_UNPAID = 'Nie możemy teraz przyjąć kolejnego zamówienia, bo z tych danych albo z tej sieci czeka już kilka nieopłaconych. Spróbuj ponownie za ok. 35 minut.';

    private Journal $journal;
    private InMemoryOrderCart $carts;
    private InMemoryOrderPrices $prices;
    private InMemoryDiscountCodeRedemptions $redemptions;
    private InMemoryOrderCatalog $catalog;
    private InMemoryStockReservations $stock;
    private InMemoryOrders $orders;
    private JournalingEventPublisher $events;
    private SequenceOrderNumbers $numbers;
    private InMemoryUnpaidOrderExpiry $expiry;
    private FixedOrderingClock $clock;
    private InMemoryCustomerSummaries $customers;
    private InMemoryUnpaidOrderClaims $claims;

    protected function setUp(): void
    {
        $this->journal = new Journal();
        $this->carts = new InMemoryOrderCart($this->journal);
        $this->prices = new InMemoryOrderPrices($this->journal)
            ->price(self::SHOES, 30_000, 6_900)
            ->price(self::CAP, 1_852, 148, 800, 'reduced')
            ->price(self::MAP, 1_000, 230)
            ->code('WITAJ10', self::CODE_ID, OrderDiscountKind::Percent, 10)
            ->reject('LATO15', self::INVALID, 'Ten kod jest nieprawidłowy albo nieaktywny.');
        $this->redemptions = new InMemoryDiscountCodeRedemptions($this->journal);
        $this->catalog = new InMemoryOrderCatalog($this->journal)
            ->variant(self::SHOES, self::SHOES_PRODUCT, 'Buty Asfalt', 'czarne, 42')
            ->variant(self::CAP, self::CAP_PRODUCT, 'Czapka Szczyt', '')
            ->variant(self::MAP, self::MAP_PRODUCT, 'Mapa Gorce', '');
        $this->stock = new InMemoryStockReservations($this->journal)->stock(self::SHOES, 5)->stock(self::CAP, 5)->stock(self::MAP, 5);
        $this->orders = new InMemoryOrders($this->journal);
        $this->events = new JournalingEventPublisher($this->journal);
        $this->numbers = new SequenceOrderNumbers($this->journal);
        $this->expiry = new InMemoryUnpaidOrderExpiry($this->journal);
        $this->clock = new FixedOrderingClock(new \DateTimeImmutable('2026-11-05 12:00:00+00:00'));
        $this->customers = new InMemoryCustomerSummaries();
        $this->claims = new InMemoryUnpaidOrderClaims($this->journal);
        $this->carts->put(self::guest(), [self::SHOES => 2, self::CAP => 1]);
    }

    public function testAGuestPlacesAnOrderOfTheWholeCartWithCopiesOfPricesAndNames(): void
    {
        $placed = $this->place(self::command());

        self::assertFalse($placed->replayed);
        $view = $placed->order;
        self::assertSame('R-100001', $view->number);
        self::assertSame('new', $view->status);
        self::assertSame('jan@example.com', $view->email);
        self::assertSame('Jan Kowalski', $view->fullName);
        self::assertSame('+48600100200', $view->phone);
        self::assertSame(['Długa 1', '00-950', 'Warszawa', 'PL'], [$view->shippingAddress->street, $view->shippingAddress->postalCode, $view->shippingAddress->city, $view->shippingAddress->country]);
        self::assertEquals([
            new OrderLineView(self::SHOES, 'Buty Asfalt', 'czarne, 42', 2, 36_900, 73_800, 0, 73_800, null),
            new OrderLineView(self::CAP, 'Czapka Szczyt', '', 1, 2_000, 2_000, 0, 2_000, null),
        ], $view->lines);
        self::assertSame([61_852, 13_948, self::TOTAL, 'PLN'], [$view->totals->net, $view->totals->vat, $view->totals->gross, $view->totals->currency]);
        self::assertEquals($this->clock->now, $view->placedAt);

        $order = $this->onlyOrder();
        self::assertSame($view->orderId, $order->id()->toString());
        self::assertTrue($order->owner()->isGuest());
        $cap = $order->lines()[1];
        self::assertSame([1_852, 148, 2_000, 800, 'reduced', self::CAP_PRODUCT], [$cap->unitPrice()->net()->amount(), $cap->unitPrice()->vat()->amount(), $cap->unitPrice()->gross()->amount(), $cap->unitPrice()->vatRateBp(), $cap->unitPrice()->taxClass(), $cap->productId()->toString()]);
        self::assertSame([], $this->carts->lines(self::guest()), 'the cart is empty');
        self::assertSame([$view->orderId => [self::SHOES => 2, self::CAP => 1]], $this->stock->reservations, 'reserved with the order id as the key');
        self::assertEquals([new OrderPlaced($view->orderId, $this->clock->now)], $this->events->published);
        self::assertSame([[self::SHOES, self::CAP]], $this->prices->asked, 'one call for all the lines');
        self::assertSame([null], $this->prices->askedCodes, 'quoted without a code');
        self::assertNull($view->discount);
        self::assertNull($order->discount());
        self::assertSame([], $this->redemptions->redeemed, 'no usage without a code');
        self::assertSame([[self::SHOES, self::CAP]], $this->catalog->asked, 'one call for all the lines');
    }

    public function testAGuestOrderGetsARandomAccessTokenAndTheDeadline(): void
    {
        $view = $this->place(self::command())->order;

        self::assertIsString($view->accessToken);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $view->accessToken, '32 random bytes in base64url');
        self::assertSame($view->accessToken, $this->onlyOrder()->accessToken()?->toString());
        self::assertEquals(new \DateTimeImmutable('2026-11-05 12:30:00+00:00'), $view->paymentDeadline);

        $this->carts->put(CartReference::guest(self::OTHER_CART), [self::MAP => 1]);
        $other = $this->place(self::command(cart: CartReference::guest(self::OTHER_CART), expectedTotalGross: 1_230, key: 'key-2'))->order;
        self::assertNotSame($view->accessToken, $other->accessToken, 'every guest order has a token of its own');
    }

    public function testACustomerOrderHasNoAccessToken(): void
    {
        $this->carts->put(CartReference::customer(self::CUSTOMER), [self::MAP => 3]);

        $view = $this->place(self::command(cart: CartReference::customer(self::CUSTOMER), expectedTotalGross: 3_690))->order;

        self::assertNull($view->accessToken);
        self::assertNull($this->onlyOrder()->accessToken());
    }

    public function testAReplayGivesTheSameAccessToken(): void
    {
        $first = $this->place(self::command())->order;

        $replayed = $this->place(self::command());

        self::assertTrue($replayed->replayed);
        self::assertNotNull($first->accessToken);
        self::assertSame($first->accessToken, $replayed->order->accessToken);
    }

    public function testThePlacementSchedulesTheExpiryFiveMinutesAfterTheDeadline(): void
    {
        $view = $this->place(self::command())->order;

        self::assertEquals([[$view->orderId, new \DateTimeImmutable('2026-11-05 12:35:00+00:00')]], $this->expiry->scheduled);
    }

    public function testAReplayOrARefusedPlacementSchedulesNoExpiry(): void
    {
        $this->place(self::command());
        $this->expiry->scheduled = [];

        $this->place(self::command());
        $this->carts->put(CartReference::guest(self::OTHER_CART), [self::MAP => 1]);
        try {
            $this->place(self::command(cart: CartReference::guest(self::OTHER_CART), expectedTotalGross: 1, key: 'key-2'));
            self::fail('Expected OrderTotalChanged.');
        } catch (OrderTotalChanged) {
            $this->addToAssertionCount(1);
        }

        self::assertSame([], $this->expiry->scheduled);
    }

    public function testTheStepsRunInTheOrderOfTheSpecification(): void
    {
        $this->place(self::command());

        // Replay check, cart lock, replay check under the lock, the limit of unpaid orders under its locks (address,
        // then identity), copies, number, cart cleared, reservation, then the order, its claim, its event and its
        // expiry last.
        self::assertSame(['find', 'lock', 'find', 'lock-unpaid', 'count-address', 'count-identity', 'prices', 'catalog', 'number', 'clear', 'reserve', 'add', 'claim', 'publish', 'schedule'], $this->journal->calls);
    }

    public function testAnAppliedCodeGivesAnOrderWithTheCopyOfTheDiscountTheLineDiscountsOfTheQuoteAndAUsage(): void
    {
        $placed = $this->place(self::command(expectedTotalGross: self::TOTAL - self::DISCOUNT, discountCode: ' witaj10 '));

        self::assertSame(['WITAJ10'], $this->prices->askedCodes, 'the code is quoted trimmed and in upper case');
        $order = $this->onlyOrder();
        $discount = $order->discount();
        self::assertNotNull($discount);
        self::assertSame(['WITAJ10', OrderDiscountKind::Percent, 10, self::DISCOUNT], [$discount->code(), $discount->kind(), $discount->value(), $discount->amount()->amount()]);
        $quote = $this->prices->lastQuote;
        self::assertNotNull($quote);
        foreach ($order->lines() as $line) {
            $quoted = $quote->lines[$line->variantId()->toString()];
            self::assertEquals($quoted->amounts->discount(), $line->discount(), 'the discount of the line is the one of the quote');
            self::assertEquals($quoted->amounts->net(), $line->lineNet(), 'the net of the line is the one of the quote');
            self::assertEquals($quoted->amounts->vat(), $line->lineVat());
            self::assertEquals($quoted->amounts->gross(), $line->lineGross());
        }
        self::assertSame(self::DISCOUNT, $order->lines()[0]->discount()->amount() + $order->lines()[1]->discount()->amount());
        self::assertSame(self::TOTAL - self::DISCOUNT, $order->totals()->gross()->amount());
        self::assertSame([[self::CODE_ID, $order->id()->toString(), self::TOTAL]], $this->redemptions->redeemed, 'one usage of the order, with the subtotal before the code');

        $view = $placed->order;
        self::assertNotNull($view->discount);
        self::assertSame(['WITAJ10', 'percent', 10, self::DISCOUNT], [$view->discount->code, $view->discount->kind, $view->discount->value, $view->discount->amountGross]);
        self::assertSame([73_800, self::DISCOUNT, 73_800 - self::DISCOUNT], [$view->lines[0]->grossBeforeDiscount, $view->lines[0]->discountGross, $view->lines[0]->lineGross]);
        self::assertSame(self::TOTAL - self::DISCOUNT, $view->totals->gross);
    }

    public function testTheUsageIsRecordedAfterTheTotalCheckAndBeforeTheNumberAndTheStock(): void
    {
        $this->place(self::command(expectedTotalGross: self::TOTAL - self::DISCOUNT, discountCode: 'WITAJ10'));

        // Cart, address, identity, then code, then stock (decisions D7 and D8 of ABUSE-LIMITS); a rejected usage takes
        // no number of the order.
        self::assertSame(['find', 'lock', 'find', 'lock-unpaid', 'count-address', 'count-identity', 'prices', 'catalog', 'redeem', 'number', 'clear', 'reserve', 'add', 'claim', 'publish', 'schedule'], $this->journal->calls);
    }

    public function testALinePromotionIsCopiedToTheOrder(): void
    {
        $promotion = PromotionCopy::of('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c6e00', 'Jesień -20%', 20, 46_125);
        $this->prices->promotion(self::SHOES, $promotion);

        $this->place(self::command());

        self::assertSame($promotion, $this->onlyOrder()->lines()[0]->promotion());
        self::assertNull($this->onlyOrder()->lines()[1]->promotion());
    }

    public function testACodeRejectedInTheQuoteIsRefusedWithItsTypeBeforeTheTotalIsCompared(): void
    {
        foreach ([self::TOTAL, 1] as $expected) {
            $this->setUp();

            try {
                $this->place(self::command(expectedTotalGross: $expected, discountCode: 'lato15'));
                self::fail('Expected DiscountCodeRejected.');
            } catch (DiscountCodeRejected $rejected) {
                self::assertSame(self::INVALID, $rejected->problemType());
                self::assertSame('Ten kod jest nieprawidłowy albo nieaktywny.', $rejected->getMessage());
            }
            self::assertSame(['find', 'lock', 'find', 'lock-unpaid', 'count-address', 'count-identity', 'prices', 'catalog'], $this->journal->calls, 'no usage, number, clear or reservation');
            self::assertSame([], $this->orders->all());
            self::assertSame([], $this->redemptions->redeemed);
            self::assertSame([self::SHOES => 2, self::CAP => 1], $this->carts->lines(self::guest()));
        }
    }

    public function testACodeRejectedWhenItsUsageIsRecordedRefusesTheWholePlacement(): void
    {
        $this->redemptions->redeemFailure = DiscountCodeRejected::of(self::USED_UP, 'Ten kod został już wykorzystany.', null);

        $this->assertRefused(DiscountCodeRejected::class, 'Ten kod został już wykorzystany.', self::command(expectedTotalGross: self::TOTAL - self::DISCOUNT, discountCode: 'WITAJ10'));
        self::assertSame(['find', 'lock', 'find', 'lock-unpaid', 'count-address', 'count-identity', 'prices', 'catalog', 'redeem'], $this->journal->calls, 'no number, clear or reservation');
        self::assertSame([], $this->stock->reservations);
        self::assertSame([], $this->events->published);
        self::assertSame([], $this->expiry->scheduled);
    }

    public function testATotalOtherThanTheOneAfterTheDiscountIsRefused(): void
    {
        $this->assertRefused(OrderTotalChanged::class, 'Kwota zamówienia się zmieniła. Sprawdź podsumowanie.', self::command(discountCode: 'WITAJ10'));
        self::assertSame([], $this->redemptions->redeemed);
    }

    public function testAReplayWithACodeGivesTheSameOrderWithoutASecondUsage(): void
    {
        $first = $this->place(self::command(expectedTotalGross: self::TOTAL - self::DISCOUNT, discountCode: 'WITAJ10'));

        $replayed = $this->place(self::command(expectedTotalGross: self::TOTAL - self::DISCOUNT, discountCode: ' Witaj10'));

        self::assertTrue($replayed->replayed);
        self::assertEquals($first->order, $replayed->order);
        self::assertCount(1, $this->redemptions->redeemed);
    }

    public function testTheSameKeyWithAnotherCodeOrWithoutTheCodeIsAnotherRequest(): void
    {
        $first = $this->place(self::command(expectedTotalGross: self::TOTAL - self::DISCOUNT, discountCode: 'WITAJ10'));
        $this->carts->put(self::guest(), [self::MAP => 1]);

        foreach ([self::command(expectedTotalGross: self::TOTAL - self::DISCOUNT), self::command(expectedTotalGross: self::TOTAL - self::DISCOUNT, discountCode: 'MINUS50')] as $other) {
            $this->assertRefused(IdempotencyKeyReused::class, 'Ten klucz żądania był już użyty dla innego zamówienia.', $other, $first);
        }
        self::assertCount(1, $this->redemptions->redeemed);
    }

    public function testABlankCodeIsNoCode(): void
    {
        $first = $this->place(self::command(discountCode: " \u{00A0}\t"));

        self::assertSame([null], $this->prices->askedCodes);
        self::assertNull($this->onlyOrder()->discount());
        self::assertSame([], $this->redemptions->redeemed);
        self::assertTrue($this->place(self::command())->replayed, 'the same request as one without the key');
        self::assertSame($first->order->orderId, $this->place(self::command(discountCode: ''))->order->orderId);
    }

    public function testTheFingerprintOfAPlacementWithoutACodeIsTheOneBeforeDiscountCodes(): void
    {
        $this->place(self::command());

        // RequestFingerprint of this request as it was computed before discount codes existed.
        self::assertSame('45d8891143adea4162fe12830184a04efb4efd464e110a5e175b9a4f7d60027f', $this->onlyOrder()->requestFingerprint()->toString());
    }

    public function testACodeLongerThan64CharactersIsRefusedBeforeAnyPort(): void
    {
        try {
            $this->place(self::command(discountCode: str_repeat('A', 65)));
            self::fail('Expected InvalidOrderInput.');
        } catch (InvalidOrderInput $invalid) {
            self::assertSame('Kod rabatowy może mieć najwyżej 64 znaki.', $invalid->getMessage());
        }
        self::assertSame([], $this->journal->calls);

        $this->setUp();
        $this->place(self::command(discountCode: ' '.str_repeat('Ą', 62).' '));
        self::assertSame([str_repeat('Ą', 62)], $this->prices->askedCodes, '64 characters (not bytes) are allowed; only ASCII letters change their case');
    }

    public function testACustomerPlacesAnOrderFromTheCustomerCart(): void
    {
        $this->carts->put(CartReference::customer(self::CUSTOMER), [self::MAP => 3]);

        $placed = $this->place(self::command(cart: CartReference::customer(self::CUSTOMER), expectedTotalGross: 3_690));

        self::assertSame(3_690, $placed->order->totals->gross);
        self::assertSame(self::CUSTOMER, $this->onlyOrder()->owner()->customerId());
        self::assertSame([], $this->carts->lines(CartReference::customer(self::CUSTOMER)));
        self::assertSame([self::SHOES => 2, self::CAP => 1], $this->carts->lines(self::guest()), 'another cart is untouched');
    }

    public function testANewOrderOfACustomerIsAddedToTheCustomerWithItsContactAndMoment(): void
    {
        $this->carts->put(CartReference::customer(self::CUSTOMER), [self::MAP => 3]);

        $placed = $this->place(self::command(cart: CartReference::customer(self::CUSTOMER), email: 'anna@example.com', fullName: 'Anna Nowak', expectedTotalGross: 3_690));

        self::assertEquals([[
            'customerId' => self::CUSTOMER,
            'orderId' => $placed->order->orderId,
            'email' => 'anna@example.com',
            'fullName' => 'Anna Nowak',
            'phone' => '+48600100200',
            'placedAt' => $this->clock->now,
        ]], $this->customers->orders);
        self::assertSame([], $this->customers->payments);
    }

    public function testAReplayOfAnOrderOfACustomerAddsNothingToTheCustomer(): void
    {
        $this->carts->put(CartReference::customer(self::CUSTOMER), [self::MAP => 3]);
        $this->place(self::command(cart: CartReference::customer(self::CUSTOMER), expectedTotalGross: 3_690));

        $replayed = $this->place(self::command(cart: CartReference::customer(self::CUSTOMER), expectedTotalGross: 3_690));

        self::assertTrue($replayed->replayed);
        self::assertCount(1, $this->customers->orders, 'the replay is no new order');
    }

    public function testAReplayUnderTheCartLockAddsNothingToTheCustomer(): void
    {
        $customer = CartReference::customer(self::CUSTOMER);
        $this->carts->put($customer, [self::MAP => 3]);
        $this->carts->whileLocking = function () use ($customer): void {
            $this->carts->whileLocking = null;
            $this->place(self::command(cart: $customer, expectedTotalGross: 3_690));
            $this->carts->put($customer, []);
        };

        $placed = $this->place(self::command(cart: $customer, expectedTotalGross: 3_690));

        self::assertTrue($placed->replayed);
        self::assertCount(1, $this->customers->orders);
    }

    public function testAnOrderOfAGuestOrARefusedPlacementAddsNoCustomer(): void
    {
        $this->place(self::command());
        $this->assertRefused(CartEmpty::class, 'Koszyk jest pusty.', self::command(cart: CartReference::customer(self::CUSTOMER), key: 'key-2'));

        self::assertSame([], $this->customers->orders, 'a guest is no customer');
    }

    public function testAGuestCartOfElevenLinesIsRefusedAfterTheLockBeforeAnyQuoteCodeNumberOrReservation(): void
    {
        $this->carts->put(self::guest(), $this->lines(11, 1));

        $this->assertRefused(GuestOrderTooLarge::class, self::TOO_LARGE, self::command(discountCode: 'WITAJ10'));
        self::assertSame(['find', 'lock', 'find'], $this->journal->calls);
        self::assertSame([], $this->redemptions->redeemed);
        self::assertSame([], $this->stock->reservations);
        self::assertSame([], $this->expiry->scheduled);
    }

    public function testAGuestCartWithElevenUnitsOfOneLineIsRefused(): void
    {
        $this->carts->put(self::guest(), [self::SHOES => 11]);

        $this->assertRefused(GuestOrderTooLarge::class, self::TOO_LARGE, self::command());
        self::assertSame(['find', 'lock', 'find'], $this->journal->calls);
    }

    public function testAGuestCartOfTenLinesOfTenUnitsIsPlaced(): void
    {
        $this->carts->put(self::guest(), $this->lines(10, 10));

        $placed = $this->place(self::command(expectedTotalGross: 10 * 10 * 2_000));

        self::assertCount(10, $placed->order->lines);
    }

    public function testAnEmptyGuestCartIsRefusedAsEmptyNotAsTooLarge(): void
    {
        $this->carts->put(self::guest(), []);

        $this->assertRefused(CartEmpty::class, 'Koszyk jest pusty.', self::command());
    }

    public function testTheSizeIsCheckedBeforeTheOtherRefusalsOfTheCart(): void
    {
        // 11 lines, the last one without a price (not orderable): the size comes first (D5).
        $this->carts->put(self::guest(), $this->lines(10, 1) + ['0192f0c4-7c1a-7c4e-9a6e-0000000000ff' => 1]);

        $this->assertRefused(GuestOrderTooLarge::class, self::TOO_LARGE, self::command());
    }

    public function testThePlacementClaimsTheOrderForTheAddressAndTheEmailUntilItsExpiry(): void
    {
        $view = $this->place(self::command(email: ' Jan+Sklep@Example.com '))->order;

        self::assertEquals([$view->orderId => ['address' => self::ADDRESS, 'identity' => 'email:jan@example.com', 'expiresAt' => new \DateTimeImmutable('2026-11-05 12:35:00+00:00')]], $this->claims->claims);
        self::assertSame([[self::ADDRESS, 'email:jan@example.com']], $this->claims->locked);
    }

    public function testAnOrderOfACustomerIsClaimedForTheAccountNotForTheEmail(): void
    {
        $this->carts->put(CartReference::customer(self::CUSTOMER), [self::MAP => 1]);

        $view = $this->place(self::command(cart: CartReference::customer(self::CUSTOMER), expectedTotalGross: 1_230))->order;

        self::assertSame('customer:'.self::CUSTOMER, $this->claims->claims[$view->orderId]['identity']);
    }

    public function testAPlacementOverTheLimitOfTheAddressIsRefusedAfterTheCartChecksBeforeTheQuote(): void
    {
        $this->claims->existing(self::PER_ADDRESS, self::ADDRESS, 'email:other@example.com', $this->clock->now->modify('+35 minutes'));

        $this->assertRefused(TooManyUnpaidOrders::class, self::TOO_MANY_UNPAID, self::command(discountCode: 'WITAJ10', expectedTotalGross: self::TOTAL - self::DISCOUNT));

        self::assertSame(['find', 'lock', 'find', 'lock-unpaid', 'count-address'], $this->journal->calls);
        self::assertSame([], $this->redemptions->redeemed);
        self::assertSame([], $this->stock->reservations);
        self::assertSame([], $this->expiry->scheduled);
        self::assertSame([self::SHOES => 2, self::CAP => 1], $this->carts->lines(self::guest()), 'the cart keeps its lines');
        self::assertCount(self::PER_ADDRESS, $this->claims->claims, 'nothing claimed');
    }

    public function testAPlacementOverTheLimitOfTheEmailIsRefusedFromAnotherAddress(): void
    {
        $this->claims->existing(self::PER_IDENTITY, '198.51.100.1', 'email:jan@example.com', $this->clock->now->modify('+35 minutes'));

        $this->assertRefused(TooManyUnpaidOrders::class, self::TOO_MANY_UNPAID, self::command(email: 'JAN+x@example.com'));
    }

    public function testAnEmptyCartOrATooLargeGuestOrderIsRefusedBeforeTheLimit(): void
    {
        $this->claims->existing(self::PER_ADDRESS, self::ADDRESS, 'email:other@example.com', $this->clock->now->modify('+35 minutes'));
        $this->carts->put(self::guest(), []);
        $this->assertRefused(CartEmpty::class, 'Koszyk jest pusty.', self::command());

        $this->carts->put(self::guest(), [self::SHOES => 11]);
        $this->assertRefused(GuestOrderTooLarge::class, self::TOO_LARGE, self::command());
    }

    public function testAReplayAtAnExhaustedLimitReturnsTheOrderAlsoFromAnotherAddress(): void
    {
        $first = $this->place(self::command())->order;
        $this->claims->existing(self::PER_ADDRESS, self::ADDRESS, 'email:jan@example.com', $this->clock->now->modify('+35 minutes'));
        $this->claims->existing(self::PER_ADDRESS, '198.51.100.1', 'email:jan@example.com', $this->clock->now->modify('+35 minutes'));
        $claims = $this->claims->claims;

        foreach ([self::ADDRESS, '198.51.100.1'] as $address) {
            $replayed = $this->place(self::command(address: $address));

            self::assertTrue($replayed->replayed);
            self::assertSame($first->orderId, $replayed->order->orderId);
        }
        self::assertSame($claims, $this->claims->claims, 'a replay claims nothing');
    }

    public function testAReplayOfAGuestOrderWhoseCartHasElevenLinesNowReturnsTheOrder(): void
    {
        $first = $this->place(self::command())->order;
        $this->carts->put(self::guest(), $this->lines(11, 1));

        $replayed = $this->place(self::command());

        self::assertTrue($replayed->replayed);
        self::assertSame($first->orderId, $replayed->order->orderId);
    }

    public function testARefusedPlacementClaimsNothing(): void
    {
        $this->assertRefused(OrderTotalChanged::class, 'Kwota zamówienia się zmieniła. Sprawdź podsumowanie.', self::command(expectedTotalGross: 1));

        self::assertSame([], $this->claims->claims);
    }

    public function testACustomerOrderIsNotLimitedToTheSizeOfAGuest(): void
    {
        $customer = CartReference::customer(self::CUSTOMER);
        $this->carts->put($customer, $this->lines(11, 11));

        $placed = $this->place(self::command(cart: $customer, expectedTotalGross: 11 * 11 * 2_000));

        self::assertCount(11, $placed->order->lines);
    }

    /**
     * @return array<string, int> quantity by variant id, each variant priced at 2 000 gross
     */
    private function lines(int $count, int $quantity): array
    {
        $lines = [];
        for ($i = 1; $i <= $count; ++$i) {
            $id = \sprintf('0192f0c4-7c1a-7c4e-9a6e-1%011d', $i);
            $this->prices->price($id, 1_626, 374);
            $this->catalog->variant($id, \sprintf('c0ffee00-7c1a-7c4e-9a6e-1%011d', $i), 'Produkt '.$i, '');
            $this->stock->stock($id, 100);
            $lines[$id] = $quantity;
        }

        return $lines;
    }

    public function testAnEmptyCartIsRefusedBeforeAnyCopyNumberOrReservation(): void
    {
        $this->carts->put(self::guest(), []);

        $this->assertRefused(CartEmpty::class, 'Koszyk jest pusty.', self::command());
        self::assertSame(['find', 'lock', 'find'], $this->journal->calls);
    }

    public function testACustomerWithoutACartHasAnEmptyCart(): void
    {
        $this->assertRefused(CartEmpty::class, 'Koszyk jest pusty.', self::command(cart: CartReference::customer(self::CUSTOMER)));
    }

    public function testAVariantWithoutACurrentPriceMakesTheCartNotOrderable(): void
    {
        $this->carts->put(self::guest(), [self::SHOES => 1, '0192f0c4-7c1a-7c4e-9a6e-0000000000ff' => 1]);
        $this->catalog->variant('0192f0c4-7c1a-7c4e-9a6e-0000000000ff', self::MAP_PRODUCT, 'Bez ceny', '');

        $this->assertRefused(CartNotOrderable::class, 'Część pozycji w koszyku nie może zostać kupiona. Sprawdź koszyk.', self::command(expectedTotalGross: 36_900));
        self::assertSame([self::SHOES => 1, '0192f0c4-7c1a-7c4e-9a6e-0000000000ff' => 1], $this->carts->lines(self::guest()), 'the cart keeps its lines');
    }

    public function testAVariantCatalogDoesNotKnowMakesTheCartNotOrderable(): void
    {
        $this->carts->put(self::guest(), [self::SHOES => 1, '0192f0c4-7c1a-7c4e-9a6e-0000000000ee' => 1]);
        $this->prices->price('0192f0c4-7c1a-7c4e-9a6e-0000000000ee', 100, 23);

        $this->assertRefused(CartNotOrderable::class, 'Część pozycji w koszyku nie może zostać kupiona. Sprawdź koszyk.', self::command(expectedTotalGross: 37_023));
    }

    public function testAnExpectedTotalLowerOrHigherThanTheComputedOneIsRefusedBeforeTheReservation(): void
    {
        foreach ([0, self::TOTAL - 1, self::TOTAL + 1, 1_000_000] as $expected) {
            $this->setUp();

            $this->assertRefused(OrderTotalChanged::class, 'Kwota zamówienia się zmieniła. Sprawdź podsumowanie.', self::command(expectedTotalGross: $expected));
            self::assertSame(['find', 'lock', 'find', 'lock-unpaid', 'count-address', 'count-identity', 'prices', 'catalog'], $this->journal->calls, 'no number, no clear, no reservation');
            self::assertSame([self::SHOES => 2, self::CAP => 1], $this->carts->lines(self::guest()));
        }
    }

    public function testAmountsComeFromThePricesPortWhateverTheRequestExpects(): void
    {
        $this->prices->price(self::SHOES, 40_000, 9_200);

        $this->assertRefused(OrderTotalChanged::class, 'Kwota zamówienia się zmieniła. Sprawdź podsumowanie.', self::command());

        $placed = $this->place(self::command(expectedTotalGross: 2 * 49_200 + 2_000, key: 'key-2'));
        self::assertSame(49_200, $placed->order->lines[0]->unitGross);
        self::assertSame(2 * 49_200 + 2_000, $placed->order->totals->gross);
    }

    public function testAShortageFromInventoryRefusesTheOrderWithoutTheNumberOfUnits(): void
    {
        $this->stock->stock(self::CAP, 0);

        $this->assertRefused(InsufficientStockForOrder::class, 'Nie mamy już tylu sztuk części produktów. Sprawdź koszyk.', self::command());
        self::assertSame([], $this->stock->reservations);
        self::assertSame([], $this->events->published);
        self::assertSame(['find', 'lock', 'find', 'lock-unpaid', 'count-address', 'count-identity', 'prices', 'catalog', 'number', 'clear', 'reserve'], $this->journal->calls, 'the bus transaction rolls back the cleared cart');
    }

    public function testARepeatedRequestGivesTheSameOrderWithoutTheCartLockOrAnyChange(): void
    {
        $first = $this->place(self::command());
        $this->carts->put(self::guest(), [self::MAP => 1]);
        $this->journal->calls = [];

        $replayed = $this->place(self::command());

        self::assertTrue($replayed->replayed);
        self::assertEquals($first->order, $replayed->order);
        self::assertSame(['find'], $this->journal->calls, 'the fast path: no cart lock');
        self::assertSame([self::MAP => 1], $this->carts->lines(self::guest()), 'a line added after the order stays');
        self::assertCount(1, $this->stock->reservations);
        self::assertCount(1, $this->events->published);
        self::assertCount(1, $this->orders->all());
    }

    public function testARepeatedRequestAfterTheGuestCartWasMergedOrExpiredGivesTheSameOrder(): void
    {
        $first = $this->place(self::command());
        $this->carts->markGone(self::guest());

        $replayed = $this->place(self::command());

        self::assertTrue($replayed->replayed);
        self::assertSame($first->order->orderId, $replayed->order->orderId);
    }

    public function testARepeatedRequestWithTheSameNormalisedContentIsAReplay(): void
    {
        $first = $this->place(self::command());

        $replayed = $this->place(new PlaceOrder(CartReference::guest(strtoupper(self::CART)), ' jan@example.com ', ' Jan Kowalski ', '+48 600-100-200', ' Długa 1 ', '00-950', 'Warszawa ', self::TOTAL, 'key-1'));

        self::assertTrue($replayed->replayed);
        self::assertSame($first->order->orderId, $replayed->order->orderId);
    }

    public function testAnOrderPlacedWhileWaitingForTheCartLockIsReplayedUnderTheLock(): void
    {
        $this->carts->whileLocking = function (): void {
            $this->carts->whileLocking = null;
            // A concurrent request with the same key and content held the lock and placed the order.
            $this->place(self::command());
            $this->carts->put(self::guest(), []);
        };

        $placed = $this->place(self::command());

        self::assertTrue($placed->replayed);
        self::assertCount(1, $this->orders->all());
        self::assertCount(1, $this->stock->reservations);
    }

    public function testTheSameKeyWithOtherContentIsRefusedWithoutDataOfTheOrder(): void
    {
        $first = $this->place(self::command());
        $this->carts->put(self::guest(), [self::MAP => 1]);

        foreach ([
            self::command(fullName: 'Jan Nowak'),
            self::command(expectedTotalGross: 1_230),
            self::command(email: 'ewa@example.com'),
        ] as $other) {
            $this->assertRefused(IdempotencyKeyReused::class, 'Ten klucz żądania był już użyty dla innego zamówienia.', $other, $first);
        }
        self::assertSame([self::MAP => 1], $this->carts->lines(self::guest()), 'the cart is untouched');
        self::assertCount(1, $this->stock->reservations);
    }

    public function testTheSameKeyAndDataFromAnotherGuestCartIsRefused(): void
    {
        $first = $this->place(self::command());
        $this->carts->put(CartReference::guest(self::OTHER_CART), [self::SHOES => 2, self::CAP => 1]);
        $this->journal->calls = [];

        $this->assertRefused(IdempotencyKeyReused::class, 'Ten klucz żądania był już użyty dla innego zamówienia.', self::command(cart: CartReference::guest(self::OTHER_CART)), $first);
        self::assertSame(['find', 'lock', 'find'], $this->journal->calls);
        self::assertSame([self::SHOES => 2, self::CAP => 1], $this->carts->lines(CartReference::guest(self::OTHER_CART)), 'the cart of the other guest keeps its lines');
    }

    public function testTheSameKeyAndDataFromAnotherCustomerIsRefused(): void
    {
        $this->carts->put(CartReference::customer(self::CUSTOMER), [self::MAP => 1]);
        $this->carts->put(CartReference::customer('kc-sub-2'), [self::MAP => 1]);
        $first = $this->place(self::command(cart: CartReference::customer(self::CUSTOMER), expectedTotalGross: 1_230));

        $this->assertRefused(IdempotencyKeyReused::class, 'Ten klucz żądania był już użyty dla innego zamówienia.', self::command(cart: CartReference::customer('kc-sub-2'), expectedTotalGross: 1_230), $first);
        self::assertSame([self::MAP => 1], $this->carts->lines(CartReference::customer('kc-sub-2')));
    }

    public function testAnUnknownGuestCartIsNotFound(): void
    {
        $this->carts->markGone(self::guest());

        $this->expectException(CartGone::class);

        $this->place(self::command());
    }

    public function testInvalidContactIsRefusedBeforeAnyPort(): void
    {
        try {
            $this->place(self::command(phone: '12345'));
            self::fail('Expected InvalidOrderInput.');
        } catch (InvalidOrderInput $invalid) {
            self::assertSame('Podaj 9-cyfrowy numer telefonu.', $invalid->getMessage());
        }
        self::assertSame([], $this->journal->calls);
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    private function assertRefused(string $expected, string $message, PlaceOrder $command, ?PlacedOrder $earlier = null): void
    {
        $ordersBefore = \count($this->orders->all());
        try {
            $this->place($command);
            self::fail('Expected '.$expected);
        } catch (\Throwable $refused) {
            self::assertInstanceOf($expected, $refused);
            self::assertSame($message, $refused->getMessage());
            if (null !== $earlier) {
                foreach ([$earlier->order->orderId, $earlier->order->number, $earlier->order->email] as $data) {
                    self::assertStringNotContainsString($data, $refused->getMessage());
                }
            }
        }
        self::assertCount($ordersBefore, $this->orders->all(), 'no order is added');
    }

    private function onlyOrder(): Order
    {
        $orders = $this->orders->all();
        self::assertCount(1, $orders);

        return $orders[0];
    }

    private function place(PlaceOrder $command): PlacedOrder
    {
        $handler = new PlaceOrderHandler($this->orders, $this->carts, $this->prices, $this->redemptions, $this->catalog, $this->stock, $this->numbers, $this->expiry, $this->events, $this->clock, $this->customers, new UnpaidOrderLimit($this->claims, $this->claims, $this->clock, self::PER_ADDRESS, self::PER_IDENTITY));

        return $handler($command);
    }

    private static function guest(): CartReference
    {
        return CartReference::guest(self::CART);
    }

    private static function command(
        ?CartReference $cart = null,
        string $email = 'jan@example.com',
        string $fullName = 'Jan Kowalski',
        string $phone = '600 100 200',
        int $expectedTotalGross = self::TOTAL,
        string $key = 'key-1',
        ?string $discountCode = null,
        string $address = self::ADDRESS,
    ): PlaceOrder {
        return new PlaceOrder($cart ?? self::guest(), $email, $fullName, $phone, 'Długa 1', '00-950', 'Warszawa', $expectedTotalGross, $key, $discountCode, $address);
    }
}
