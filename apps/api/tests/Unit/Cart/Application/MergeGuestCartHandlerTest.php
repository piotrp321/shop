<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Application;

use App\Cart\Application\Command\MergeGuestCart;
use App\Cart\Application\Command\MergeGuestCartHandler;
use App\Cart\Application\Command\MergeResult;
use App\Cart\Application\ProductDataLoader;
use App\Cart\Application\View\CartLineView;
use App\Cart\Application\View\CartViewBuilder;
use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\CartOwner;
use App\Cart\Domain\CartStatus;
use App\Cart\Domain\Quantity;
use App\Cart\Domain\VariantId;
use App\Tests\Unit\Cart\FixedClock;
use App\Tests\Unit\Cart\InMemoryCarts;
use App\Tests\Unit\Cart\InMemoryProducts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MergeGuestCartHandlerTest extends TestCase
{
    private const string CUSTOMER = 'kc-sub-1';
    private const string A = '0192f0c4-7c1a-7c4e-9a6e-00000000000a';
    private const string B = '0192f0c4-7c1a-7c4e-9a6e-00000000000b';
    private const string UNKNOWN_CART = '6f1c2a3b-4d5e-4f60-8a7b-9c0d1e2f3a4b';

    private InMemoryCarts $carts;
    private InMemoryProducts $products;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->carts = new InMemoryCarts();
        $this->products = new InMemoryProducts()->variant(self::A, 'Buty', 10_000, 5)->variant(self::B, 'Czapka', 2_500, 50);
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-03 12:00:00+00:00'));
    }

    public function testTheGuestLinesMoveIntoTheCustomerCartWhichIsReturned(): void
    {
        $customer = $this->storedCustomer('+100 days', [$this->line(self::B, 1, '-3 days')]);
        $guest = $this->storedGuest(CartStatus::Open, '+20 days', [$this->line(self::A, 2, '-1 day'), $this->line(self::B, 2, '-1 day')]);

        $result = $this->merge($guest->toString());

        self::assertSame('merged', $result->result);
        self::assertFalse($result->truncated);
        self::assertSame($customer->toString(), $result->cart->id);
        self::assertSame([[self::B, 3, 'purchasable'], [self::A, 2, 'purchasable']], self::lines($result));
        self::assertSame(5, $result->cart->totals->itemCount);

        $storedGuest = $this->carts->find($guest);
        self::assertSame(CartStatus::Merged, $storedGuest?->status());
        self::assertSame([], $storedGuest->lines());
        self::assertTrue($customer->equals($storedGuest->mergedInto() ?? CartId::generate()));
        self::assertSame([[self::B, 3], [self::A, 2]], $this->storedLines($customer));
    }

    public function testACustomerWithoutACartGetsOneWithTheGuestLines(): void
    {
        $guest = $this->storedGuest(CartStatus::Open, '+20 days', [$this->line(self::A, 1, '-1 day')]);

        $result = $this->merge($guest->toString());

        self::assertSame('merged', $result->result);
        $customer = $this->carts->findForCustomer(self::CUSTOMER);
        self::assertNotNull($customer);
        self::assertSame($customer->id()->toString(), $result->cart->id);
        self::assertSame([[self::A, 1]], $this->storedLines($customer->id()));
        self::assertEquals($this->clock->now->modify('+180 days'), $customer->expiresAt());
    }

    public function testATruncatedMergeIsReported(): void
    {
        $this->storedCustomer('+100 days', [$this->line(self::A, 98, '-3 days')]);
        $guest = $this->storedGuest(CartStatus::Open, '+20 days', [$this->line(self::A, 5, '-1 day')]);

        $result = $this->merge($guest->toString());

        self::assertSame('merged', $result->result);
        self::assertTrue($result->truncated);
        self::assertSame([[self::A, 99, 'insufficient_stock']], self::lines($result), 'a merge never checks the availability');
    }

    public function testAnEmptyGuestCartIsMerged(): void
    {
        $guest = $this->storedGuest(CartStatus::Open, '+20 days', []);

        $result = $this->merge($guest->toString());

        self::assertSame('merged', $result->result);
        self::assertFalse($result->truncated);
        self::assertSame(CartStatus::Merged, $this->carts->find($guest)?->status());
    }

    public function testAnExpiredCustomerCartEndsWithTheGuestLinesOnly(): void
    {
        $customer = $this->storedCustomer('-1 second', [$this->line(self::B, 4, '-200 days')]);
        $guest = $this->storedGuest(CartStatus::Open, '+20 days', [$this->line(self::A, 1, '-1 day')]);

        $result = $this->merge($guest->toString());

        self::assertSame('merged', $result->result);
        self::assertSame([[self::A, 1, 'purchasable']], self::lines($result));
        self::assertSame([[self::A, 1]], $this->storedLines($customer));
        self::assertEquals($this->clock->now->modify('+180 days'), $this->carts->find($customer)?->expiresAt());
    }

    /**
     * @return iterable<string, array{\Closure(self): string}>
     */
    public static function nothingToMerge(): iterable
    {
        yield 'unknown' => [static fn (self $test): string => self::UNKNOWN_CART];
        yield 'not a UUID' => [static fn (self $test): string => 'not-a-uuid'];
        yield 'expired' => [static fn (self $test): string => $test->storedGuest(CartStatus::Open, '-1 second', [$test->line(self::A, 1, '-31 days')])->toString()];
        yield 'the customer cart' => [static fn (self $test): string => $test->storedCustomer('+100 days', [$test->line(self::B, 1, '-1 day')])->toString()];
        yield 'another customer cart' => [static fn (self $test): string => $test->storedCustomer('+100 days', [$test->line(self::A, 1, '-1 day')], 'kc-sub-2')->toString()];
    }

    /**
     * @param \Closure(self): string $guestCartId
     */
    #[DataProvider('nothingToMerge')]
    public function testNothingToMergeChangesNothingAndCreatesNoCart(\Closure $guestCartId): void
    {
        $id = $guestCartId($this);
        $before = $this->snapshot();

        $result = $this->merge($id);

        self::assertSame('nothing_to_merge', $result->result);
        self::assertFalse($result->truncated);
        self::assertSame(0, $this->carts->saves);
        self::assertSame($before, $this->snapshot());
        $own = $this->carts->findForCustomer(self::CUSTOMER);
        self::assertSame($own?->id()->toString(), $result->cart->id);
    }

    /**
     * @param \Closure(self): string $guestCartId
     */
    #[DataProvider('nothingToMerge')]
    public function testNothingToMergeGivesTheCartOfTheCustomer(\Closure $guestCartId): void
    {
        $customer = $this->storedCustomer('+100 days', [$this->line(self::B, 1, '-1 day')]);

        $result = $this->merge($guestCartId($this));

        self::assertSame('nothing_to_merge', $result->result);
        self::assertSame($customer->toString(), $result->cart->id);
        self::assertSame([[self::B, 1, 'purchasable']], self::lines($result));
    }

    public function testAMergedGuestCartIsAlreadyMergedAndChangesNothing(): void
    {
        $customer = $this->storedCustomer('+100 days', [$this->line(self::B, 2, '-1 day')]);
        $guest = $this->storedGuest(CartStatus::Merged, '+20 days', []);
        $before = $this->snapshot();

        $result = $this->merge($guest->toString());

        self::assertSame('already_merged', $result->result);
        self::assertFalse($result->truncated);
        self::assertSame($customer->toString(), $result->cart->id);
        self::assertSame([[self::B, 2, 'purchasable']], self::lines($result));
        self::assertSame(0, $this->carts->saves);
        self::assertSame($before, $this->snapshot());
    }

    public function testAMergedGuestCartOfACustomerWithoutACartGivesTheEmptyViewWithoutId(): void
    {
        $guest = $this->storedGuest(CartStatus::Merged, '+20 days', []);

        $result = $this->merge($guest->toString());

        self::assertSame('already_merged', $result->result);
        self::assertNull($result->cart->id);
        self::assertSame([], $result->cart->lines);
        self::assertNull($this->carts->findForCustomer(self::CUSTOMER));
    }

    public function testAnExpiredCustomerCartIsReadAsEmptyWhenNothingIsMerged(): void
    {
        $customer = $this->storedCustomer('-1 second', [$this->line(self::B, 2, '-200 days')]);

        $result = $this->merge(self::UNKNOWN_CART);

        self::assertSame('nothing_to_merge', $result->result);
        self::assertSame($customer->toString(), $result->cart->id);
        self::assertSame([], $result->cart->lines);
        self::assertSame([[self::B, 2]], $this->storedLines($customer));
    }

    public function testTheMergedGuestCartRefusesTheNextMerge(): void
    {
        $guest = $this->storedGuest(CartStatus::Open, '+20 days', [$this->line(self::A, 1, '-1 day')]);

        self::assertSame('merged', $this->merge($guest->toString())->result);
        $again = $this->merge($guest->toString());

        self::assertSame('already_merged', $again->result);
        self::assertSame([[self::A, 1, 'purchasable']], self::lines($again), 'the lines moved once');
    }

    public function merge(string $guestCartId): MergeResult
    {
        $handler = new MergeGuestCartHandler($this->carts, $this->carts, new ProductDataLoader($this->products, $this->products, $this->products), new CartViewBuilder($this->products), $this->clock);

        return $handler(new MergeGuestCart(self::CUSTOMER, $guestCartId));
    }

    /**
     * @param list<CartLine> $lines
     */
    public function storedGuest(CartStatus $status, string $expiresIn, array $lines): CartId
    {
        $id = CartId::generate();
        $merged = CartStatus::Merged === $status;
        $this->carts->add(Cart::reconstitute($id, CartOwner::guest(), $status, $merged ? CartId::generate() : null, $merged ? $this->clock->now->modify('-1 hour') : null, $lines, $this->clock->now->modify('-20 days'), $this->clock->now->modify('-10 days'), $this->clock->now->modify($expiresIn)));

        return $id;
    }

    /**
     * @param list<CartLine> $lines
     */
    public function storedCustomer(string $expiresIn, array $lines, string $customerId = self::CUSTOMER): CartId
    {
        $id = CartId::generate();
        $this->carts->add(Cart::reconstitute($id, CartOwner::customer($customerId), CartStatus::Open, null, null, $lines, $this->clock->now->modify('-300 days'), $this->clock->now->modify('-10 days'), $this->clock->now->modify($expiresIn)));

        return $id;
    }

    public function line(string $variantId, int $quantity, string $addedAgo): CartLine
    {
        return new CartLine(VariantId::fromString($variantId), Quantity::of($quantity), $this->clock->now->modify($addedAgo));
    }

    /**
     * @return list<array{string, int}>
     */
    private function storedLines(CartId $id): array
    {
        return array_map(static fn (CartLine $line): array => [$line->variantId()->toString(), $line->quantity()->value()], $this->carts->find($id)?->lines() ?? []);
    }

    /**
     * Every stored cart as it is, to compare before and after.
     */
    private function snapshot(): string
    {
        return serialize($this->carts);
    }

    /**
     * @return list<array{string, int, string}>
     */
    private static function lines(MergeResult $result): array
    {
        return array_map(static fn (CartLineView $l): array => [$l->variantId, $l->quantity, $l->state], $result->cart->lines);
    }
}
