<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Application;

use App\Cart\Application\CartRef;
use App\Cart\Application\Command\CartLineForOrder;
use App\Cart\Application\Command\ClearCartAfterOrder;
use App\Cart\Application\Command\ClearCartAfterOrderHandler;
use App\Cart\Application\Command\LockCartForOrder;
use App\Cart\Application\Command\LockCartForOrderHandler;
use App\Cart\Application\LockedCarts;
use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\CartNotFound;
use App\Cart\Domain\CartOwner;
use App\Cart\Domain\CartStatus;
use App\Cart\Domain\GuestCartMerged;
use App\Cart\Domain\Quantity;
use App\Cart\Domain\VariantId;
use App\Tests\Unit\Cart\FixedClock;
use App\Tests\Unit\Cart\InMemoryCarts;
use PHPUnit\Framework\TestCase;

/**
 * The two commands Ordering places an order with: LockCartForOrder reads the lines of the locked cart, and
 * ClearCartAfterOrder empties it once the order is placed.
 */
final class CartOrderCommandsTest extends TestCase
{
    private const string CUSTOMER = 'kc-sub-1';
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-00000000000a';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-00000000000b';
    private const string UNKNOWN_CART = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';

    private InMemoryCarts $carts;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->carts = new InMemoryCarts();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-05 12:00:00+00:00'));
    }

    public function testLockOfAGuestCartGivesItsLinesInTheOrderTheyWereAddedAndChangesNothing(): void
    {
        $cart = $this->stored(CartOwner::guest(), CartStatus::Open, '+3 days');

        $lines = $this->lock(CartRef::guest($cart->id()->toString()));

        self::assertEquals([new CartLineForOrder(self::B, 3), new CartLineForOrder(self::A, 1)], $lines);
        self::assertSame(0, $this->carts->saves);
    }

    public function testLockOfACustomerCartGivesItsLines(): void
    {
        $this->stored(CartOwner::customer(self::CUSTOMER), CartStatus::Open, '+3 days');

        self::assertEquals([new CartLineForOrder(self::B, 3), new CartLineForOrder(self::A, 1)], $this->lock(CartRef::customer(self::CUSTOMER)));
    }

    public function testLockOfAnExpiredCustomerCartGivesNoLine(): void
    {
        $this->stored(CartOwner::customer(self::CUSTOMER), CartStatus::Open, '-1 second');

        self::assertSame([], $this->lock(CartRef::customer(self::CUSTOMER)));
    }

    public function testLockForACustomerWithoutACartGivesNoLineAndCreatesNoCart(): void
    {
        self::assertSame([], $this->lock(CartRef::customer(self::CUSTOMER)));
        self::assertSame(0, $this->carts->count());
    }

    public function testLockOfAMergedGuestCartIsRefused(): void
    {
        $cart = $this->stored(CartOwner::guest(), CartStatus::Merged, '+3 days', []);

        $this->expectException(GuestCartMerged::class);

        $this->lock(CartRef::guest($cart->id()->toString()));
    }

    public function testLockOfAnExpiredGuestCartIsNotFound(): void
    {
        $cart = $this->stored(CartOwner::guest(), CartStatus::Open, '-1 second');

        $this->expectException(CartNotFound::class);

        $this->lock(CartRef::guest($cart->id()->toString()));
    }

    public function testLockOfAnUnknownGuestCartOrACustomerCartNamedAsAGuestCartIsNotFound(): void
    {
        $customerCart = $this->stored(CartOwner::customer(self::CUSTOMER), CartStatus::Open, '+3 days');

        foreach ([self::UNKNOWN_CART, 'not-a-uuid', $customerCart->id()->toString()] as $id) {
            try {
                $this->lock(CartRef::guest($id));
                self::fail('Expected CartNotFound for '.$id);
            } catch (CartNotFound $missing) {
                self::assertSame('Koszyk nie jest już dostępny.', $missing->getMessage());
            }
        }
    }

    public function testClearEmptiesAGuestCartKeepsItOpenAndMovesItsExpiry(): void
    {
        $cart = $this->stored(CartOwner::guest(), CartStatus::Open, '+3 days');

        $this->clear(CartRef::guest($cart->id()->toString()));

        $stored = $this->carts->find($cart->id());
        self::assertNotNull($stored);
        self::assertSame([], $stored->lines());
        self::assertSame(CartStatus::Open, $stored->status());
        self::assertEquals($this->clock->now->modify('+30 days'), $stored->expiresAt());
    }

    public function testClearEmptiesACustomerCart(): void
    {
        $cart = $this->stored(CartOwner::customer(self::CUSTOMER), CartStatus::Open, '+3 days');

        $this->clear(CartRef::customer(self::CUSTOMER));

        $stored = $this->carts->find($cart->id());
        self::assertNotNull($stored);
        self::assertSame([], $stored->lines());
        self::assertEquals($this->clock->now->modify('+180 days'), $stored->expiresAt());
    }

    public function testClearForACustomerWithoutACartChangesNothing(): void
    {
        $this->clear(CartRef::customer(self::CUSTOMER));

        self::assertSame(0, $this->carts->count());
        self::assertSame(0, $this->carts->saves);
    }

    public function testClearOfAMergedGuestCartIsRefused(): void
    {
        $cart = $this->stored(CartOwner::guest(), CartStatus::Merged, '+3 days', []);

        $this->expectException(GuestCartMerged::class);

        $this->clear(CartRef::guest($cart->id()->toString()));
    }

    /**
     * @return list<CartLineForOrder>
     */
    private function lock(CartRef $ref): array
    {
        return new LockCartForOrderHandler(new LockedCarts($this->carts), $this->clock)(new LockCartForOrder($ref));
    }

    private function clear(CartRef $ref): void
    {
        new ClearCartAfterOrderHandler(new LockedCarts($this->carts), $this->carts, $this->clock)(new ClearCartAfterOrder($ref));
    }

    /**
     * @param list<CartLine>|null $lines B (3 units) then A (1 unit) when null
     */
    private function stored(CartOwner $owner, CartStatus $status, string $expiresIn, ?array $lines = null): Cart
    {
        $now = $this->clock->now;
        $lines ??= [
            new CartLine(VariantId::fromString(self::B), Quantity::of(3), $now->modify('-2 hours')),
            new CartLine(VariantId::fromString(self::A), Quantity::of(1), $now->modify('-1 hour')),
        ];
        $cart = Cart::reconstitute(CartId::generate(), $owner, $status, null, null, $lines, $now->modify('-10 days'), $now->modify('-1 hour'), $now->modify($expiresIn));
        $this->carts->add($cart);

        return $cart;
    }
}
