<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Domain;

use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\CartNotFound;
use App\Cart\Domain\CartOwner;
use App\Cart\Domain\CartStatus;
use App\Cart\Domain\GuestCartMerged;
use App\Cart\Domain\Quantity;
use App\Cart\Domain\VariantId;
use PHPUnit\Framework\TestCase;

/**
 * Cart::clearAfterOrder(): the cart an order was placed from loses all its lines and stays open, with a new expiry.
 */
final class CartClearAfterOrderTest extends TestCase
{
    private const string CART = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';
    private const string V1 = '0192f0c4-7c1a-7c4e-9a6e-000000000001';
    private const string V2 = '0192f0c4-7c1a-7c4e-9a6e-000000000002';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-05 12:00:00+00:00');
    }

    public function testAGuestCartLosesEveryLineStaysOpenAndIsTouched(): void
    {
        $cart = $this->cart(CartOwner::guest(), CartStatus::Open, $this->now->modify('+3 days'));

        $cart->clearAfterOrder($this->now);

        self::assertSame([], $cart->lines());
        self::assertSame(CartStatus::Open, $cart->status());
        self::assertEquals($this->now, $cart->updatedAt());
        self::assertEquals($this->now->modify('+30 days'), $cart->expiresAt());
    }

    public function testACustomerCartLosesEveryLineAndIsTouched(): void
    {
        $cart = $this->cart(CartOwner::customer('kc-sub-1'), CartStatus::Open, $this->now->modify('+3 days'));

        $cart->clearAfterOrder($this->now);

        self::assertSame([], $cart->lines());
        self::assertEquals($this->now, $cart->updatedAt());
        self::assertEquals($this->now->modify('+180 days'), $cart->expiresAt());
    }

    public function testAMergedGuestCartIsRefused(): void
    {
        $cart = $this->cart(CartOwner::guest(), CartStatus::Merged, $this->now->modify('+3 days'), []);

        $this->expectException(GuestCartMerged::class);

        $cart->clearAfterOrder($this->now);
    }

    public function testAnExpiredGuestCartIsNotFoundAndKeepsItsLines(): void
    {
        $cart = $this->cart(CartOwner::guest(), CartStatus::Open, $this->now->modify('-1 second'));

        try {
            $cart->clearAfterOrder($this->now);
            self::fail('Expected CartNotFound.');
        } catch (CartNotFound) {
        }

        self::assertCount(2, $cart->lines());
    }

    /**
     * @param list<CartLine>|null $lines two lines when null
     */
    private function cart(CartOwner $owner, CartStatus $status, \DateTimeImmutable $expiresAt, ?array $lines = null): Cart
    {
        $lines ??= [
            new CartLine(VariantId::fromString(self::V1), Quantity::of(2), $this->now->modify('-2 hours')),
            new CartLine(VariantId::fromString(self::V2), Quantity::of(1), $this->now->modify('-1 hour')),
        ];

        return Cart::reconstitute(CartId::fromString(self::CART), $owner, $status, null, null, $lines, $this->now->modify('-10 days'), $this->now->modify('-1 hour'), $expiresAt);
    }
}
