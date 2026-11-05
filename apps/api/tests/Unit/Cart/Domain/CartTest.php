<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Domain;

use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\CartLineLimit;
use App\Cart\Domain\CartLineNotFound;
use App\Cart\Domain\CartLineQuantityLimit;
use App\Cart\Domain\CartNotFound;
use App\Cart\Domain\CartOwner;
use App\Cart\Domain\CartStatus;
use App\Cart\Domain\GuestCartMerged;
use App\Cart\Domain\InsufficientStock;
use App\Cart\Domain\ProductNotPurchasable;
use App\Cart\Domain\ProductOffer;
use App\Cart\Domain\Quantity;
use App\Cart\Domain\VariantId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase
{
    private const string CART = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';
    private const string V1 = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string V2 = '0192f0c4-7c1a-7c4e-9a6e-000000000002';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-03 12:00:00+00:00');
    }

    public function testNewGuestCartIsOpenEmptyAndExpiresIn30Days(): void
    {
        $cart = Cart::createForGuest(CartId::fromString(self::CART), $this->now);

        self::assertSame(self::CART, $cart->id()->toString());
        self::assertTrue($cart->owner()->isGuest());
        self::assertSame(CartStatus::Open, $cart->status());
        self::assertNull($cart->mergedInto());
        self::assertNull($cart->mergedAt());
        self::assertSame([], $cart->lines());
        self::assertEquals($this->now, $cart->createdAt());
        self::assertEquals($this->now, $cart->updatedAt());
        self::assertEquals(new \DateTimeImmutable('2026-12-03 12:00:00+00:00'), $cart->expiresAt());
    }

    public function testNewCustomerCartExpiresIn180Days(): void
    {
        $cart = Cart::createForCustomer(CartId::fromString(self::CART), 'kc-sub-1', $this->now);

        self::assertSame('kc-sub-1', $cart->owner()->customerId());
        self::assertSame(CartStatus::Open, $cart->status());
        self::assertEquals(new \DateTimeImmutable('2027-05-02 12:00:00+00:00'), $cart->expiresAt());
    }

    public function testAddCreatesALineAndTouchesTheCart(): void
    {
        $cart = $this->guestCart();
        $later = $this->now->modify('+1 day');

        $cart->add(self::variant(self::V1), Quantity::of(2), self::offer(), $later);

        self::assertSame([[self::V1, 2]], self::lines($cart));
        self::assertEquals($later, $cart->lines()[0]->addedAt());
        self::assertEquals($later, $cart->updatedAt());
        self::assertEquals($later->modify('+30 days'), $cart->expiresAt());
    }

    public function testAddOfAVariantInTheCartIncreasesItsQuantityAndKeepsItsPlace(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(2), self::offer(), $this->now);
        $cart->add(self::variant(self::V2), Quantity::of(1), self::offer(), $this->now);

        $cart->add(self::variant(strtoupper(self::V1)), Quantity::of(3), self::offer(), $this->now->modify('+1 hour'));

        self::assertSame([[self::V1, 5], [self::V2, 1]], self::lines($cart));
        self::assertEquals($this->now, $cart->lines()[0]->addedAt());
    }

    /**
     * Two variants (e.g. two sizes) of one product are two lines; the cart knows only variants, and the limit of 99
     * units applies to each line on its own.
     */
    public function testTwoVariantsAreTwoLinesWithTheirOwnLimitOfUnits(): void
    {
        $cart = $this->guestCart();

        $cart->add(self::variant(self::V1), Quantity::of(99), self::offer(), $this->now);
        $cart->add(self::variant(self::V2), Quantity::of(99), self::offer(), $this->now);

        self::assertSame([[self::V1, 99], [self::V2, 99]], self::lines($cart));
        $this->expectException(CartLineQuantityLimit::class);
        $cart->add(self::variant(self::V2), Quantity::of(1), self::offer(), $this->now);
    }

    public function testAddUpTo99UnitsIsAllowed(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(98), self::offer(), $this->now);

        $cart->add(self::variant(self::V1), Quantity::of(1), self::offer(), $this->now);

        self::assertSame([[self::V1, 99]], self::lines($cart));
    }

    public function testAddOver99UnitsIsRefusedWithoutChange(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(99), self::offer(), $this->now);
        $updatedAt = $cart->updatedAt();

        try {
            $cart->add(self::variant(self::V1), Quantity::of(1), self::offer(), $this->now->modify('+1 hour'));
            self::fail('Expected CartLineQuantityLimit.');
        } catch (CartLineQuantityLimit $limit) {
            self::assertSame('Można dodać najwyżej 99 sztuk jednej pozycji.', $limit->getMessage());
        }
        self::assertSame([[self::V1, 99]], self::lines($cart));
        self::assertEquals($updatedAt, $cart->updatedAt());
    }

    public function testThe50thLineIsAllowedAndThe51stRefused(): void
    {
        $cart = $this->guestCart();
        for ($i = 1; $i <= 49; ++$i) {
            $cart->add(self::numberedVariant($i), Quantity::of(1), self::offer(), $this->now);
        }

        $cart->add(self::numberedVariant(50), Quantity::of(1), self::offer(), $this->now);
        self::assertCount(50, $cart->variantIds());

        try {
            $cart->add(self::numberedVariant(51), Quantity::of(1), self::offer(), $this->now);
            self::fail('Expected CartLineLimit.');
        } catch (CartLineLimit $limit) {
            self::assertSame('Koszyk może zawierać najwyżej 50 różnych pozycji.', $limit->getMessage());
        }
        self::assertCount(50, $cart->lines());
    }

    public function testAFullCartStillIncreasesTheQuantityOfItsLines(): void
    {
        $cart = $this->guestCart();
        for ($i = 1; $i <= 50; ++$i) {
            $cart->add(self::numberedVariant($i), Quantity::of(1), self::offer(), $this->now);
        }

        $cart->add(self::numberedVariant(7), Quantity::of(2), self::offer(), $this->now);

        self::assertSame(3, $cart->lines()[6]->quantity()->value());
    }

    /**
     * @return iterable<string, array{ProductOffer}>
     */
    public static function notPurchasable(): iterable
    {
        yield 'no current price' => [new ProductOffer(false, 10)];
        yield 'nothing available' => [new ProductOffer(true, 0)];
        yield 'neither' => [new ProductOffer(false, 0)];
    }

    #[DataProvider('notPurchasable')]
    public function testAddOfAProductThatCannotBeBoughtIsRefused(ProductOffer $offer): void
    {
        $cart = $this->guestCart();

        try {
            $cart->add(self::variant(self::V1), Quantity::of(1), $offer, $this->now);
            self::fail('Expected ProductNotPurchasable.');
        } catch (ProductNotPurchasable $refused) {
            self::assertSame('Ten produkt jest chwilowo niedostępny w sprzedaży.', $refused->getMessage());
        }
        self::assertSame([], $cart->lines());
    }

    public function testAddUpToTheAvailableUnitsIsAllowedAndBeyondRefusedWithoutTheNumber(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(2), new ProductOffer(true, 3), $this->now);
        $cart->add(self::variant(self::V1), Quantity::of(1), new ProductOffer(true, 3), $this->now);

        try {
            $cart->add(self::variant(self::V1), Quantity::of(1), new ProductOffer(true, 3), $this->now);
            self::fail('Expected InsufficientStock.');
        } catch (InsufficientStock $short) {
            self::assertSame('Nie mamy tylu sztuk tego produktu.', $short->getMessage());
        }
        self::assertSame([[self::V1, 3]], self::lines($cart));
    }

    public function testTheQuantityLimitIsCheckedBeforeTheOffer(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(99), self::offer(), $this->now);

        $this->expectException(CartLineQuantityLimit::class);

        $cart->add(self::variant(self::V1), Quantity::of(1), new ProductOffer(false, 0), $this->now);
    }

    public function testChangeQuantitySetsTheValue(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(2), self::offer(), $this->now);
        $later = $this->now->modify('+2 days');

        $cart->changeQuantity(self::variant(self::V1), Quantity::of(7), self::offer(), $later);

        self::assertSame([[self::V1, 7]], self::lines($cart));
        self::assertEquals($later, $cart->updatedAt());
        self::assertEquals($later->modify('+30 days'), $cart->expiresAt());
    }

    public function testDecreaseAndTheSameQuantityNeedNoOfferAndAreAlwaysAllowed(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(5), self::offer(), $this->now);

        self::assertFalse($cart->needsOfferToChange(self::variant(self::V1), Quantity::of(5)));
        self::assertFalse($cart->needsOfferToChange(self::variant(self::V1), Quantity::of(1)));
        $cart->changeQuantity(self::variant(self::V1), Quantity::of(5), null, $this->now);
        $cart->changeQuantity(self::variant(self::V1), Quantity::of(1), null, $this->now);

        self::assertSame([[self::V1, 1]], self::lines($cart));
    }

    public function testDecreaseIsAllowedForAProductThatCannotBeBought(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(5), self::offer(), $this->now);

        $cart->changeQuantity(self::variant(self::V1), Quantity::of(2), new ProductOffer(false, 0), $this->now);

        self::assertSame([[self::V1, 2]], self::lines($cart));
    }

    public function testIncreaseNeedsTheOffer(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(1), self::offer(), $this->now);

        self::assertTrue($cart->needsOfferToChange(self::variant(self::V1), Quantity::of(2)));
        self::assertFalse($cart->needsOfferToChange(self::variant(self::V2), Quantity::of(2)), 'no line, nothing to increase');

        $this->expectException(\LogicException::class);
        $cart->changeQuantity(self::variant(self::V1), Quantity::of(2), null, $this->now);
    }

    /**
     * @return iterable<string, array{ProductOffer, class-string<\Throwable>}>
     */
    public static function refusedIncreases(): iterable
    {
        yield 'no current price' => [new ProductOffer(false, 10), ProductNotPurchasable::class];
        yield 'nothing available' => [new ProductOffer(true, 0), ProductNotPurchasable::class];
        yield 'more than available' => [new ProductOffer(true, 3), InsufficientStock::class];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('refusedIncreases')]
    public function testIncreaseIsCheckedAgainstTheOffer(ProductOffer $offer, string $expected): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(1), self::offer(), $this->now);

        try {
            $cart->changeQuantity(self::variant(self::V1), Quantity::of(4), $offer, $this->now);
            self::fail('Expected '.$expected);
        } catch (\Throwable $refused) {
            self::assertInstanceOf($expected, $refused);
        }
        self::assertSame([[self::V1, 1]], self::lines($cart));
    }

    public function testIncreaseUpToTheAvailableUnitsIsAllowed(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(1), self::offer(), $this->now);

        $cart->changeQuantity(self::variant(self::V1), Quantity::of(3), new ProductOffer(true, 3), $this->now);

        self::assertSame([[self::V1, 3]], self::lines($cart));
    }

    public function testChangeOfAMissingLineIsNotFound(): void
    {
        $cart = $this->guestCart();

        try {
            $cart->changeQuantity(self::variant(self::V1), Quantity::of(1), self::offer(), $this->now);
            self::fail('Expected CartLineNotFound.');
        } catch (CartLineNotFound $missing) {
            self::assertSame('Tej pozycji nie ma w koszyku.', $missing->getMessage());
        }
    }

    public function testRemoveDeletesTheLineAndTouchesTheCart(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(1), self::offer(), $this->now);
        $cart->add(self::variant(self::V2), Quantity::of(1), self::offer(), $this->now);
        $later = $this->now->modify('+3 days');

        $cart->remove(self::variant(self::V1), $later);

        self::assertSame([[self::V2, 1]], self::lines($cart));
        self::assertEquals($later, $cart->updatedAt());
    }

    public function testRemoveOfAMissingLineChangesNothing(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V1), Quantity::of(1), self::offer(), $this->now);

        $cart->remove(self::variant(self::V2), $this->now->modify('+3 days'));

        self::assertSame([[self::V1, 1]], self::lines($cart));
        self::assertEquals($this->now, $cart->updatedAt());
    }

    /**
     * @return iterable<string, array{\Closure(Cart, \DateTimeImmutable): void}>
     */
    public static function changes(): iterable
    {
        yield 'add' => [static fn (Cart $cart, \DateTimeImmutable $now) => $cart->add(self::variant(self::V2), Quantity::of(1), self::offer(), $now)];
        yield 'change quantity' => [static fn (Cart $cart, \DateTimeImmutable $now) => $cart->changeQuantity(self::variant(self::V1), Quantity::of(1), null, $now)];
        yield 'remove' => [static fn (Cart $cart, \DateTimeImmutable $now) => $cart->remove(self::variant(self::V1), $now)];
        yield 'remove of a missing line' => [static fn (Cart $cart, \DateTimeImmutable $now) => $cart->remove(self::variant(self::V2), $now)];
    }

    /**
     * @param \Closure(Cart, \DateTimeImmutable): void $change
     */
    #[DataProvider('changes')]
    public function testAMergedGuestCartRefusesEveryChange(\Closure $change): void
    {
        $cart = $this->storedGuestCart(CartStatus::Merged, $this->now->modify('+10 days'), []);

        try {
            $change($cart, $this->now);
            self::fail('Expected GuestCartMerged.');
        } catch (GuestCartMerged $merged) {
            self::assertSame('Koszyk nie jest już dostępny.', $merged->getMessage());
        }
    }

    /**
     * @param \Closure(Cart, \DateTimeImmutable): void $change
     */
    #[DataProvider('changes')]
    public function testAnExpiredGuestCartIsNotFoundForEveryChange(\Closure $change): void
    {
        $cart = $this->storedGuestCart(CartStatus::Open, $this->now->modify('-1 second'), [new CartLine(self::variant(self::V1), Quantity::of(2), $this->now->modify('-40 days'))]);

        try {
            $change($cart, $this->now);
            self::fail('Expected CartNotFound.');
        } catch (CartNotFound $missing) {
            self::assertSame('Koszyk nie jest już dostępny.', $missing->getMessage());
        }
        self::assertSame([[self::V1, 2]], self::lines($cart));
    }

    public function testAGuestCartExpiringRightNowIsStillValid(): void
    {
        $cart = $this->storedGuestCart(CartStatus::Open, $this->now, []);

        self::assertFalse($cart->isExpired($this->now));
        self::assertTrue($cart->isExpired($this->now->modify('+1 second')));
        $cart->add(self::variant(self::V1), Quantity::of(1), self::offer(), $this->now);
        self::assertCount(1, $cart->lines());
    }

    public function testTouchMovesTheExpiryOfAGuestBy30AndOfACustomerBy180Days(): void
    {
        $guest = $this->guestCart();
        $customer = Cart::createForCustomer(CartId::generate(), 'kc-sub-1', $this->now);
        $later = new \DateTimeImmutable('2027-01-15 08:30:00+00:00');

        $guest->touch($later);
        $customer->touch($later);

        self::assertEquals(new \DateTimeImmutable('2027-02-14 08:30:00+00:00'), $guest->expiresAt());
        self::assertEquals(new \DateTimeImmutable('2027-07-14 08:30:00+00:00'), $customer->expiresAt());
        self::assertEquals($later, $guest->updatedAt());
        self::assertEquals($later, $customer->updatedAt());
    }

    public function testClearIfExpiredEmptiesAnExpiredCustomerCartOnly(): void
    {
        $line = new CartLine(self::variant(self::V1), Quantity::of(2), $this->now->modify('-200 days'));
        $expired = $this->storedCustomerCart($this->now->modify('-1 second'), [$line]);
        $valid = $this->storedCustomerCart($this->now->modify('+1 day'), [$line]);
        $expiredGuest = $this->storedGuestCart(CartStatus::Open, $this->now->modify('-1 second'), [$line]);

        $expired->clearIfExpired($this->now);
        $valid->clearIfExpired($this->now);
        $expiredGuest->clearIfExpired($this->now);

        self::assertSame([], $expired->lines());
        self::assertSame([[self::V1, 2]], self::lines($valid));
        self::assertSame([[self::V1, 2]], self::lines($expiredGuest), 'a guest cart is never cleared, it is gone');
    }

    public function testAChangeOfAnExpiredCustomerCartStartsFromAnEmptyCart(): void
    {
        $cart = $this->storedCustomerCart($this->now->modify('-1 second'), [
            new CartLine(self::variant(self::V1), Quantity::of(2), $this->now->modify('-200 days')),
        ]);

        $cart->add(self::variant(self::V2), Quantity::of(1), self::offer(), $this->now);

        self::assertSame([[self::V2, 1]], self::lines($cart));
        self::assertEquals($this->now->modify('+180 days'), $cart->expiresAt());
    }

    public function testAnExpiredCustomerCartHasNoLineToChange(): void
    {
        $cart = $this->storedCustomerCart($this->now->modify('-1 second'), [
            new CartLine(self::variant(self::V1), Quantity::of(2), $this->now->modify('-200 days')),
        ]);
        $cart->openForChange($this->now);

        self::assertFalse($cart->needsOfferToChange(self::variant(self::V1), Quantity::of(5)));
        $this->expectException(CartLineNotFound::class);
        $cart->changeQuantity(self::variant(self::V1), Quantity::of(5), null, $this->now);
    }

    public function testReadingAGuestCartShowsItsLinesUnlessItIsExpiredOrMerged(): void
    {
        $line = new CartLine(self::variant(self::V1), Quantity::of(2), $this->now);
        $open = $this->storedGuestCart(CartStatus::Open, $this->now->modify('+1 day'), [$line, new CartLine(self::variant(self::V2), Quantity::of(3), $this->now)]);

        self::assertSame([$line], \array_slice($open->linesAt($this->now), 0, 1));
        self::assertSame(5, $open->itemCountAt($this->now));

        try {
            $this->storedGuestCart(CartStatus::Open, $this->now->modify('-1 second'), [$line])->linesAt($this->now);
            self::fail('Expected CartNotFound.');
        } catch (CartNotFound) {
        }

        $this->expectException(GuestCartMerged::class);
        $this->storedGuestCart(CartStatus::Merged, $this->now->modify('+1 day'), [])->itemCountAt($this->now);
    }

    public function testReadingAnExpiredCustomerCartShowsAnEmptyCart(): void
    {
        $cart = $this->storedCustomerCart($this->now->modify('-1 second'), [
            new CartLine(self::variant(self::V1), Quantity::of(2), $this->now->modify('-200 days')),
        ]);

        self::assertSame([], $cart->linesAt($this->now));
        self::assertSame(0, $cart->itemCountAt($this->now));
        self::assertCount(1, $cart->lines(), 'reading changes nothing');
    }

    public function testReconstituteKeepsTheStoredState(): void
    {
        $mergedAt = $this->now->modify('-1 hour');
        $into = CartId::generate();
        $cart = Cart::reconstitute(
            CartId::fromString(self::CART),
            CartOwner::guest(),
            CartStatus::Merged,
            $into,
            $mergedAt,
            [],
            $this->now->modify('-2 days'),
            $mergedAt,
            $this->now->modify('+28 days'),
        );

        self::assertSame(CartStatus::Merged, $cart->status());
        self::assertSame($into, $cart->mergedInto());
        self::assertSame($mergedAt, $cart->mergedAt());
        self::assertEquals($this->now->modify('-2 days'), $cart->createdAt());
    }

    public function testACustomerCartCannotBeMerged(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Cart::reconstitute(CartId::generate(), CartOwner::customer('kc-sub-1'), CartStatus::Merged, null, null, [], $this->now, $this->now, $this->now);
    }

    public function testVariantIdsFollowTheOrderOfTheLines(): void
    {
        $cart = $this->guestCart();
        $cart->add(self::variant(self::V2), Quantity::of(1), self::offer(), $this->now);
        $cart->add(self::variant(self::V1), Quantity::of(1), self::offer(), $this->now);

        self::assertSame([self::V2, self::V1], array_map(static fn (VariantId $id): string => $id->toString(), $cart->variantIds()));
    }

    private function guestCart(): Cart
    {
        return Cart::createForGuest(CartId::fromString(self::CART), $this->now);
    }

    /**
     * @param list<CartLine> $lines
     */
    private function storedGuestCart(CartStatus $status, \DateTimeImmutable $expiresAt, array $lines): Cart
    {
        return Cart::reconstitute(CartId::fromString(self::CART), CartOwner::guest(), $status, null, null, $lines, $this->now->modify('-20 days'), $this->now->modify('-10 days'), $expiresAt);
    }

    /**
     * @param list<CartLine> $lines
     */
    private function storedCustomerCart(\DateTimeImmutable $expiresAt, array $lines): Cart
    {
        return Cart::reconstitute(CartId::fromString(self::CART), CartOwner::customer('kc-sub-1'), CartStatus::Open, null, null, $lines, $this->now->modify('-400 days'), $this->now->modify('-200 days'), $expiresAt);
    }

    private static function variant(string $id): VariantId
    {
        return VariantId::fromString($id);
    }

    private static function numberedVariant(int $number): VariantId
    {
        return VariantId::fromString(\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $number));
    }

    private static function offer(): ProductOffer
    {
        return new ProductOffer(true, 1000);
    }

    /**
     * @return list<array{string, int}>
     */
    private static function lines(Cart $cart): array
    {
        return array_map(static fn (CartLine $line): array => [$line->variantId()->toString(), $line->quantity()->value()], $cart->lines());
    }
}
