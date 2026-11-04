<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Application;

use App\Cart\Application\CartRef;
use App\Cart\Application\Command\AddCartItem;
use App\Cart\Application\Command\AddCartItemHandler;
use App\Cart\Application\Command\ChangeCartItemQuantity;
use App\Cart\Application\Command\ChangeCartItemQuantityHandler;
use App\Cart\Application\Command\CreateGuestCart;
use App\Cart\Application\Command\CreateGuestCartHandler;
use App\Cart\Application\Command\RemoveCartItem;
use App\Cart\Application\Command\RemoveCartItemHandler;
use App\Cart\Application\LockedCarts;
use App\Cart\Application\ProductDataLoader;
use App\Cart\Application\Query\GetCart;
use App\Cart\Application\Query\GetCartHandler;
use App\Cart\Application\Query\GetCartSummary;
use App\Cart\Application\Query\GetCartSummaryHandler;
use App\Cart\Application\View\CartLineView;
use App\Cart\Application\View\CartView;
use App\Cart\Application\View\CartViewBuilder;
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
use App\Cart\Domain\InvalidQuantity;
use App\Cart\Domain\ProductId;
use App\Cart\Domain\ProductNotFound;
use App\Cart\Domain\ProductNotPurchasable;
use App\Cart\Domain\Quantity;
use App\Tests\Unit\Cart\FixedClock;
use App\Tests\Unit\Cart\InMemoryCarts;
use App\Tests\Unit\Cart\InMemoryProducts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CartHandlersTest extends TestCase
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
        $this->products = new InMemoryProducts()->product(self::A, 'Buty', 10_000, 5)->product(self::B, 'Czapka', 2_500, 50);
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-03 12:00:00+00:00'));
    }

    public function testCreateGuestCartStoresAnEmptyGuestCartAndAsksNoPort(): void
    {
        $view = $this->createGuestCart();

        self::assertNotNull($view->id);
        self::assertSame([], $view->lines);
        self::assertSame(0, $view->totals->itemCount);
        $stored = $this->carts->find(CartId::fromString($view->id));
        self::assertNotNull($stored);
        self::assertTrue($stored->owner()->isGuest());
        self::assertSame(['summaries' => [], 'current' => [], 'of' => []], $this->products->calls());
    }

    public function testGuestAddsChangesAndRemovesItems(): void
    {
        $cart = $this->guest();

        $added = $this->add($cart, self::A, 2);
        self::assertSame([[self::A, 2, 'purchasable', 20_000]], self::lines($added));
        self::assertSame(20_000, $added->totals->gross);

        $this->add($cart, self::B, 1);
        $changed = $this->change($cart, self::A, 1);
        self::assertSame([[self::A, 1, 'purchasable', 10_000], [self::B, 1, 'purchasable', 2_500]], self::lines($changed));

        $removed = $this->remove($cart, self::A);
        self::assertSame([[self::B, 1, 'purchasable', 2_500]], self::lines($removed));
        self::assertSame(1, $removed->totals->itemCount);
        self::assertSame([self::B], self::storedLines($cart));
    }

    public function testEveryPortIsAskedOnceForAllTheLinesOfAnAddition(): void
    {
        $cart = $this->guest();
        $this->add($cart, self::A, 1);
        $this->products->resetCalls();

        $this->add($cart, self::B, 1);

        self::assertSame(['summaries' => [[self::A, self::B]], 'current' => [[self::A, self::B]], 'of' => [[self::A, self::B]]], $this->products->calls());
    }

    public function testRemovalOfAMissingLineIsASuccessWithoutChange(): void
    {
        $cart = $this->guest();
        $this->add($cart, self::A, 1);
        $saves = $this->carts->saves;
        $before = $this->carts->find(CartId::fromString($cart->cartId ?? ''))?->updatedAt();
        $this->clock->now = $this->clock->now->modify('+1 day');

        $view = $this->remove($cart, self::B);

        self::assertSame([[self::A, 1, 'purchasable', 10_000]], self::lines($view));
        self::assertEquals($before, $this->carts->find(CartId::fromString($cart->cartId ?? ''))?->updatedAt());
        self::assertGreaterThanOrEqual($saves, $this->carts->saves);
    }

    public function testAProductUnknownToCatalogCannotBeAdded(): void
    {
        $cart = $this->guest();

        $this->expectException(ProductNotFound::class);
        $this->add($cart, '0192f0c4-7c1a-7c4e-9a6e-0000000000ff', 1);
    }

    public function testAProductIdThatIsNotAUuidIsAnUnknownProduct(): void
    {
        $this->expectException(ProductNotFound::class);
        $this->add($this->guest(), 'not-a-uuid', 1);
    }

    /**
     * @return iterable<string, array{?int, int, class-string<\Throwable>}>
     */
    public static function refusedAdditions(): iterable
    {
        yield 'no price' => [null, 5, ProductNotPurchasable::class];
        yield 'nothing available' => [1000, 0, ProductNotPurchasable::class];
        yield 'more than available' => [1000, 1, InsufficientStock::class];
    }

    /**
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('refusedAdditions')]
    public function testAdditionIsCheckedAgainstPricingAndInventory(?int $gross, int $available, string $expected): void
    {
        $this->products->product(self::A, 'Buty', $gross, $available);
        $cart = $this->guest();

        $this->expectException($expected);
        $this->add($cart, self::A, 2);
    }

    public function testTheLimitsAreRefusedWithoutChange(): void
    {
        $cart = $this->guest();
        $this->products->product(self::A, 'Buty', 1000, 1000);
        $this->add($cart, self::A, 99);

        try {
            $this->add($cart, self::A, 1);
            self::fail('Expected CartLineQuantityLimit.');
        } catch (CartLineQuantityLimit) {
        }
        self::assertSame(99, $this->carts->find(CartId::fromString($cart->cartId ?? ''))?->lines()[0]->quantity()->value());
    }

    public function testThe51stLineIsRefused(): void
    {
        $lines = [];
        for ($i = 1; $i <= 50; ++$i) {
            $id = \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $i);
            $this->products->product($id);
            $lines[] = new CartLine(ProductId::fromString($id), Quantity::of(1), $this->clock->now);
        }
        $cart = $this->storedGuest(CartStatus::Open, $this->clock->now->modify('+1 day'), $lines);

        $this->expectException(CartLineLimit::class);
        $this->add($cart, self::A, 1);
    }

    public function testAQuantityOutOfRangeIsInvalid(): void
    {
        $this->expectException(InvalidQuantity::class);
        $this->add($this->guest(), self::A, 100);
    }

    public function testDecreaseAsksNoPortForTheOfferAndWorksForAProductThatCannotBeBought(): void
    {
        $cart = $this->guest();
        $this->add($cart, self::A, 3);
        $this->products->product(self::A, 'Buty', null, 0);
        $this->products->resetCalls();

        $view = $this->change($cart, self::A, 1);

        self::assertSame([[self::A, 1, 'no_price', null]], self::lines($view));
        self::assertCount(1, $this->products->calls()['current'], 'only for the view');
    }

    public function testDecreaseOfALineWhoseProductCatalogForgotIsAllowed(): void
    {
        $cart = $this->guest();
        $this->add($cart, self::A, 3);
        $this->products->forget(self::A);

        $view = $this->change($cart, self::A, 2);

        self::assertSame([[self::A, 2, 'unavailable_product', 10_000]], [[$view->lines[0]->productId, $view->lines[0]->quantity, $view->lines[0]->state, $view->lines[0]->unitPrice?->gross]]);
    }

    public function testIncreaseOfALineWhoseProductCatalogForgotIsNotFound(): void
    {
        $cart = $this->guest();
        $this->add($cart, self::A, 1);
        $this->products->forget(self::A);

        $this->expectException(ProductNotFound::class);
        $this->change($cart, self::A, 2);
    }

    public function testIncreaseIsCheckedAgainstInventory(): void
    {
        $cart = $this->guest();
        $this->add($cart, self::A, 1);

        $this->expectException(InsufficientStock::class);
        $this->change($cart, self::A, 6);
    }

    public function testChangeOfAMissingLineIsNotFound(): void
    {
        $this->expectException(CartLineNotFound::class);
        $this->change($this->guest(), self::A, 1);
    }

    /**
     * @return iterable<string, array{\Closure(self, CartRef): mixed}>
     */
    public static function guestOperations(): iterable
    {
        yield 'get' => [static fn (self $test, CartRef $ref): mixed => $test->getCart($ref)];
        yield 'summary' => [static fn (self $test, CartRef $ref): mixed => $test->summary($ref)];
        yield 'add' => [static fn (self $test, CartRef $ref): mixed => $test->add($ref, self::A, 1)];
        yield 'change' => [static fn (self $test, CartRef $ref): mixed => $test->change($ref, self::A, 1)];
        yield 'remove' => [static fn (self $test, CartRef $ref): mixed => $test->remove($ref, self::A)];
    }

    /**
     * @param \Closure(self, CartRef): mixed $operation
     */
    #[DataProvider('guestOperations')]
    public function testAnUnknownGuestCartIsNotFound(\Closure $operation): void
    {
        $this->expectException(CartNotFound::class);
        $operation($this, CartRef::guest(self::UNKNOWN_CART));
    }

    /**
     * @param \Closure(self, CartRef): mixed $operation
     */
    #[DataProvider('guestOperations')]
    public function testAGuestCartIdThatIsNotAUuidIsNotFound(\Closure $operation): void
    {
        $this->expectException(CartNotFound::class);
        $operation($this, CartRef::guest('not-a-uuid'));
    }

    /**
     * @param \Closure(self, CartRef): mixed $operation
     */
    #[DataProvider('guestOperations')]
    public function testAnExpiredGuestCartIsNotFound(\Closure $operation): void
    {
        $cart = $this->storedGuest(CartStatus::Open, $this->clock->now->modify('-1 second'), [new CartLine(ProductId::fromString(self::A), Quantity::of(1), $this->clock->now->modify('-31 days'))]);

        $this->expectException(CartNotFound::class);
        $operation($this, $cart);
    }

    /**
     * @param \Closure(self, CartRef): mixed $operation
     */
    #[DataProvider('guestOperations')]
    public function testAMergedGuestCartIsNotFound(\Closure $operation): void
    {
        $cart = $this->storedGuest(CartStatus::Merged, $this->clock->now->modify('+10 days'), []);

        $this->expectException(GuestCartMerged::class);
        $operation($this, $cart);
    }

    /**
     * @param \Closure(self, CartRef): mixed $operation
     */
    #[DataProvider('guestOperations')]
    public function testTheIdOfACustomerCartIsNoGuestCart(\Closure $operation): void
    {
        $this->add(CartRef::customer(self::CUSTOMER), self::A, 1);
        $customerCart = $this->carts->findForCustomer(self::CUSTOMER);
        self::assertNotNull($customerCart);

        try {
            $operation($this, CartRef::guest($customerCart->id()->toString()));
            self::fail('Expected CartNotFound.');
        } catch (CartNotFound) {
        }
        self::assertSame(1, $this->carts->findForCustomer(self::CUSTOMER)?->lines()[0]->quantity()->value());
    }

    public function testGetCartOfACustomerWithoutACartIsEmptyWithoutIdAndCreatesNothing(): void
    {
        $view = $this->getCart(CartRef::customer(self::CUSTOMER));
        $summary = $this->summary(CartRef::customer(self::CUSTOMER));

        self::assertNull($view->id);
        self::assertSame([], $view->lines);
        self::assertSame(0, $summary->itemCount);
        self::assertSame(0, $this->carts->count());
    }

    public function testRemovalFromACustomerWithoutACartIsAnEmptyViewAndCreatesNothing(): void
    {
        $view = $this->remove(CartRef::customer(self::CUSTOMER), self::A);

        self::assertNull($view->id);
        self::assertSame([], $view->lines);
        self::assertSame(0, $this->carts->count());
    }

    public function testTheFirstAdditionOfACustomerCreatesTheCart(): void
    {
        $view = $this->add(CartRef::customer(self::CUSTOMER), self::A, 2);

        $stored = $this->carts->findForCustomer(self::CUSTOMER);
        self::assertNotNull($stored);
        self::assertSame($stored->id()->toString(), $view->id);
        self::assertSame([[self::A, 2, 'purchasable', 20_000]], self::lines($view));
        self::assertEquals($this->clock->now->modify('+180 days'), $stored->expiresAt());
    }

    public function testCustomerCartIsReadAfterChanges(): void
    {
        $ref = CartRef::customer(self::CUSTOMER);
        $this->add($ref, self::A, 2);
        $this->add($ref, self::B, 3);

        $view = $this->getCart($ref);

        self::assertSame([[self::A, 2, 'purchasable', 20_000], [self::B, 3, 'purchasable', 7_500]], self::lines($view));
        self::assertSame(5, $this->summary($ref)->itemCount);
    }

    public function testAnExpiredCustomerCartIsReadAsEmptyAndItsFirstChangeClearsItsLines(): void
    {
        $ref = CartRef::customer(self::CUSTOMER);
        $this->add($ref, self::A, 2);
        $id = $this->carts->findForCustomer(self::CUSTOMER)?->id()->toString();
        $this->clock->now = $this->clock->now->modify('+181 days');

        $read = $this->getCart($ref);
        self::assertSame($id, $read->id);
        self::assertSame([], $read->lines);
        self::assertSame(0, $this->summary($ref)->itemCount);
        self::assertCount(1, $this->carts->findForCustomer(self::CUSTOMER)?->lines() ?? [], 'a read changes nothing');

        $changed = $this->add($ref, self::B, 1);

        self::assertSame([[self::B, 1, 'purchasable', 2_500]], self::lines($changed));
        self::assertEquals($this->clock->now->modify('+180 days'), $this->carts->findForCustomer(self::CUSTOMER)?->expiresAt());
    }

    public function testAGuestCartIsExtendedByEveryChange(): void
    {
        $cart = $this->guest();
        $this->clock->now = $this->clock->now->modify('+29 days');

        $this->add($cart, self::A, 1);

        self::assertEquals($this->clock->now->modify('+30 days'), $this->carts->find(CartId::fromString($cart->cartId ?? ''))?->expiresAt());
    }

    public function testAReadOfAGuestCartShowsItsLines(): void
    {
        $cart = $this->guest();
        $this->add($cart, self::A, 2);

        self::assertSame([[self::A, 2, 'purchasable', 20_000]], self::lines($this->getCart($cart)));
        self::assertSame(2, $this->summary($cart)->itemCount);
    }

    public function testASummaryAsksNoPort(): void
    {
        $cart = $this->guest();
        $this->add($cart, self::A, 2);
        $this->products->resetCalls();

        $this->summary($cart);

        self::assertSame(['summaries' => [], 'current' => [], 'of' => []], $this->products->calls());
    }

    public function createGuestCart(): CartView
    {
        return new CreateGuestCartHandler($this->carts, new CartViewBuilder(), $this->clock)(new CreateGuestCart());
    }

    public function add(CartRef $cart, string $productId, int $quantity): CartView
    {
        return new AddCartItemHandler(new LockedCarts($this->carts), $this->carts, $this->loader(), new CartViewBuilder(), $this->clock)(new AddCartItem($cart, $productId, $quantity));
    }

    public function change(CartRef $cart, string $productId, int $quantity): CartView
    {
        return new ChangeCartItemQuantityHandler(new LockedCarts($this->carts), $this->carts, $this->loader(), new CartViewBuilder(), $this->clock)(new ChangeCartItemQuantity($cart, $productId, $quantity));
    }

    public function remove(CartRef $cart, string $productId): CartView
    {
        return new RemoveCartItemHandler(new LockedCarts($this->carts), $this->carts, $this->loader(), new CartViewBuilder(), $this->clock)(new RemoveCartItem($cart, $productId));
    }

    public function getCart(CartRef $cart): CartView
    {
        return new GetCartHandler($this->carts, $this->loader(), new CartViewBuilder(), $this->clock)(new GetCart($cart));
    }

    public function summary(CartRef $cart): \App\Cart\Application\Query\CartSummary
    {
        return new GetCartSummaryHandler($this->carts, $this->clock)(new GetCartSummary($cart));
    }

    private function loader(): ProductDataLoader
    {
        return new ProductDataLoader($this->products, $this->products, $this->products);
    }

    private function guest(): CartRef
    {
        $view = $this->createGuestCart();
        self::assertNotNull($view->id);

        return CartRef::guest($view->id);
    }

    /**
     * @param list<CartLine> $lines
     */
    private function storedGuest(CartStatus $status, \DateTimeImmutable $expiresAt, array $lines): CartRef
    {
        $id = CartId::generate();
        $this->carts->add(Cart::reconstitute($id, CartOwner::guest(), $status, null, null, $lines, $this->clock->now->modify('-20 days'), $this->clock->now->modify('-10 days'), $expiresAt));

        return CartRef::guest($id->toString());
    }

    /**
     * @return list<string>
     */
    private function storedLines(CartRef $cart): array
    {
        return array_map(static fn (ProductId $id): string => $id->toString(), $this->carts->find(CartId::fromString($cart->cartId ?? ''))?->productIds() ?? []);
    }

    /**
     * @return list<array{string, int, string, ?int}>
     */
    private static function lines(CartView $view): array
    {
        return array_map(static fn (CartLineView $l): array => [$l->productId, $l->quantity, $l->state, $l->lineTotal?->gross], $view->lines);
    }
}
