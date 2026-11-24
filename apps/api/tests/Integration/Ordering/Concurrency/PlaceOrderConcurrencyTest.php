<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Concurrency;

use App\Cart\Application\CartRef;
use App\Cart\Application\Command\AddCartItem;
use App\Cart\Application\Command\ChangeCartItemQuantity;
use App\Cart\Application\Command\MergeGuestCart;
use App\Cart\Application\Command\RemoveCartItem;
use App\Cart\Domain\CartLineNotFound;
use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Domain\CartContentChanged;
use App\Ordering\Domain\CartEmpty;
use App\Ordering\Domain\IdempotencyKeyReused;
use App\Ordering\Domain\InsufficientStockForOrder;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Inventory\CatalogFixture;
use App\Tests\Integration\Inventory\Concurrency\ConcurrentCommands;
use App\Tests\Integration\Inventory\Concurrency\Outcome;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Scenarios O1 to O12 of the Checkout specification ("Testy – Współbieżność") on PostgreSQL: every participant is
 * a process of its own dispatching PlaceOrder (or MergeGuestCart) on command.bus (ConcurrentCommands of the Inventory
 * tests), all start at once. Each scenario runs 5 rounds on fresh variants, carts and keys; after every round the
 * reserved units of every variant of the test are the sum of its active reservations, and every order of the test
 * has a reservation with its id as the key and the same lines, the delivery method of the test with its cost
 * (docs/features/delivery/spec.md, W5) and the lines of the `expectedLines` of the request that placed it. The rows commit, so they are deleted after the test.
 */
final class PlaceOrderConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;
    /** 1 000 net + 23 % VAT, the price of every variant of the test. */
    private const int GROSS = 1_230;
    /** The cost of the delivery method of every placement, below any threshold (it has none). */
    private const int DELIVERY = 1_999;

    private string $deliveryMethod;

    private Connection $control;
    /** @var list<string> */
    private array $products = [];
    /** @var list<string> */
    private array $variants = [];
    /** @var list<string> */
    private array $carts = [];
    /** @var list<string> */
    private array $customers = [];
    /** @var list<string> */
    private array $keys = [];
    /** @var array<string, array<string, int>> the expected lines of each placement by its key, sorted by variant */
    private array $expected = [];

    protected function setUp(): void
    {
        // Boots the kernel, so its cache is warm before the participants boot theirs.
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $this->control->executeStatement("SET lock_timeout = '10s'");
        $this->deliveryMethod = OrderingFixture::deliveryMethod($this->control, self::DELIVERY);
    }

    protected function tearDown(): void
    {
        if ([] !== $this->keys) {
            $orderIds = $this->control->fetchFirstColumn('SELECT id FROM ordering_order WHERE idempotency_key IN (?)', [$this->keys], [ArrayParameterType::STRING]);
            OrderingFixture::deleteOrders($this->control, array_values(array_filter($orderIds, is_string(...))));
        }
        CartFixture::delete($this->control, $this->carts, $this->customers);
        if ([] !== $this->variants) {
            $this->control->executeStatement('DELETE FROM pricing_variant_price WHERE variant_id IN (?)', [$this->variants], [ArrayParameterType::STRING]);
        }
        StockFixture::delete($this->control, $this->variants, []);
        CatalogFixture::delete($this->control, $this->products);
        OrderingFixture::deleteDeliveryMethods($this->control, [$this->deliveryMethod]);
        $this->control->close();
        parent::tearDown();
    }

    public function testO1TenGuestsBuyingTheLastThreeUnitsGetExactlyThreeOrders(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(3);
            $carts = [];
            $commands = [];
            for ($i = 0; $i < 10; ++$i) {
                $carts[] = $cart = $this->guestCart([[$variant, 1]]);
                $commands[] = $this->place(CartReference::guest($cart), self::GROSS, [[$variant, 1]]);
            }

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'O1 round '.$round;
            self::assertSame([InsufficientStockForOrder::class => 7, Outcome::SUCCESS => 3], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            self::assertSame([3, 3], OrderingFixture::stock($this->control, $variant), $message.': 3 reserved, none sold over the stock');
            foreach ($outcomes as $index => $outcome) {
                $expected = Outcome::SUCCESS === $outcome->kind ? [] : [[$variant, 1]];
                self::assertSame($expected, CartFixture::lines($this->control, $carts[$index]), $message.': a buyer has an empty cart, the others keep their line');
            }
            $this->assertInvariant($message);
        }
    }

    public function testO2TenPlacementsWithOneKeyFromOneCartGiveOneOrderAndNineReplays(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(10);
            $cart = $this->guestCart([[$variant, 2]]);
            $command = $this->place(CartReference::guest($cart), 2 * self::GROSS, [[$variant, 2]]);

            $outcomes = ConcurrentCommands::run($this->control, array_fill(0, 10, $command));

            $message = 'O2 round '.$round;
            self::assertSame([Outcome::SUCCESS => 10], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            self::assertSame([false => 1, true => 9], self::replays($outcomes), $message.': one placement, nine replays');
            self::assertCount(1, array_unique(self::orderIds($outcomes)), $message.': the same order every time');
            self::assertSame(1, $this->orderCount($command->idempotencyKey), $message);
            self::assertSame([10, 2], OrderingFixture::stock($this->control, $variant), $message.': reserved once');
            $this->assertInvariant($message);
        }
    }

    public function testO3TwoPlacementsWithDifferentKeysFromOneCartGiveOneOrder(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(10);
            $cart = $this->guestCart([[$variant, 1]]);

            $outcomes = ConcurrentCommands::run($this->control, [
                $this->place(CartReference::guest($cart), self::GROSS, [[$variant, 1]]),
                $this->place(CartReference::guest($cart), self::GROSS, [[$variant, 1]]),
            ]);

            $message = 'O3 round '.$round;
            self::assertSame([CartEmpty::class => 1, Outcome::SUCCESS => 1], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            self::assertSame([10, 1], OrderingFixture::stock($this->control, $variant), $message);
            $this->assertInvariant($message);
        }
    }

    public function testO4TwoCartsWithTwoLastUnitsInOppositeOrderGiveOneOrderWithoutADeadlock(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $a = $this->variant(1);
            $b = $this->variant(1);
            $first = $this->guestCart([[$a, 1], [$b, 1]]);
            $second = $this->guestCart([[$b, 1], [$a, 1]]);

            $outcomes = ConcurrentCommands::run($this->control, [
                $this->place(CartReference::guest($first), 2 * self::GROSS, [[$a, 1], [$b, 1]]),
                $this->place(CartReference::guest($second), 2 * self::GROSS, [[$b, 1], [$a, 1]]),
            ]);

            $message = 'O4 round '.$round;
            self::assertSame([InsufficientStockForOrder::class => 1, Outcome::SUCCESS => 1], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            self::assertSame([1, 1], OrderingFixture::stock($this->control, $a), $message);
            self::assertSame([1, 1], OrderingFixture::stock($this->control, $b), $message);
            $this->assertInvariant($message);
        }
    }

    /**
     * Odd rounds: the placement expects the list and the total the checkout showed before the merge (the customer's own
     * line), so it places an order of exactly that line when it runs first and finds the content changed after the
     * merge. Even rounds: the list and the total of a checkout read after the merge (both lines), so it finds the
     * content changed when it runs first and places an order of both lines after the merge. The guest line is never
     * lost and an order always has the lines of its list.
     */
    public function testO5APlacementOfACustomerAtOnceWithAMergeIntoThatCustomerLosesNoLine(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customer = $this->customer();
            $own = $this->variant(10);
            $guestVariant = $this->variant(10);
            $this->carts[] = CartFixture::cart($this->control, customerId: $customer, expiresAt: '+180 days', lines: [[$own, 1]]);
            $guest = $this->guestCart([[$guestVariant, 2]]);
            $placement = 1 === $round % 2
                ? $this->place(CartReference::customer($customer), self::GROSS, [[$own, 1]])
                : $this->place(CartReference::customer($customer), 3 * self::GROSS, [[$own, 1], [$guestVariant, 2]]);

            $outcomes = ConcurrentCommands::run($this->control, [$placement, new MergeGuestCart($customer, $guest)]);

            $message = 'O5 round '.$round.(1 === $round % 2 ? ' (list before the merge)' : ' (list after the merge)');
            [$placed, $merge] = $outcomes;
            self::assertSame(Outcome::SUCCESS, $merge->kind, $message.': '.$merge->message);
            self::assertSame('merged', $merge->result['result'] ?? null, $message);
            self::assertContains($placed->kind, [Outcome::SUCCESS, CartContentChanged::class], $message.': '.$placed->message);
            $ordered = [];
            if (Outcome::SUCCESS === $placed->kind) {
                $orderId = self::orderIdOf($placed);
                self::assertNotNull($orderId);
                $ordered = OrderingFixture::orderLines($this->control, $orderId);
            }
            $inCart = [];
            foreach (CartFixture::lines($this->control, $this->customerCart($customer)) as [$variant, $quantity]) {
                $inCart[$variant] = $quantity;
            }
            self::assertSame(1, ($ordered[$own] ?? 0) + ($inCart[$own] ?? 0), $message.': the own line is in the order or in the cart');
            self::assertSame(2, ($ordered[$guestVariant] ?? 0) + ($inCart[$guestVariant] ?? 0), $message.': the guest line is in the order or in the cart');
            self::assertSame([], array_intersect_key($ordered, $inCart), $message.': a line is in one place only');
            if (CartContentChanged::class === $placed->kind) {
                self::assertSame(self::sorted([$own => 1, $guestVariant => 2]), self::sorted($inCart), $message.': all the lines are in the cart');
            }
            $this->assertInvariant($message);
        }
    }

    public function testO6TenReplaysAfterTheGuestCartWasMergedOrExpiredGiveTheSameOrder(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(10);
            $cart = $this->guestCart([[$variant, 1]]);
            $command = $this->place(CartReference::guest($cart), self::GROSS, [[$variant, 1]]);
            [$first] = ConcurrentCommands::run($this->control, [$command]);
            self::assertSame(Outcome::SUCCESS, $first->kind, (string) $first->message);
            $orderId = self::orderIdOf($first);
            if (1 === $round % 2) {
                [$merge] = ConcurrentCommands::run($this->control, [new MergeGuestCart($this->customer(), $cart)]);
                self::assertSame('merged', $merge->result['result'] ?? null, (string) $merge->message);
            } else {
                $this->control->executeStatement("UPDATE cart_cart SET expires_at = now() - interval '1 day' WHERE id = ?", [$cart]);
            }

            $outcomes = ConcurrentCommands::run($this->control, array_fill(0, 10, $command));

            $message = 'O6 round '.$round.(1 === $round % 2 ? ' (merged)' : ' (expired)');
            self::assertSame([Outcome::SUCCESS => 10], self::kinds($outcomes), $message.': no 404; '.self::messages($outcomes));
            self::assertSame([true => 10], self::replays($outcomes), $message);
            self::assertSame([$orderId], array_values(array_unique(self::orderIds($outcomes))), $message.': the same order');
            self::assertSame([10, 1], OrderingFixture::stock($this->control, $variant), $message.': no new reservation');
            $this->assertInvariant($message);
        }
    }

    /**
     * Odd rounds: two guest carts; even rounds: two customers. Both send the same key and the same data.
     */
    public function testO7OneKeyFromTwoCartsAtOnceGivesOneOrderAndOneIdempotencyKeyReusedWithoutItsData(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(10);
            if (1 === $round % 2) {
                $refs = [CartReference::guest($this->guestCart([[$variant, 1]])), CartReference::guest($this->guestCart([[$variant, 1]]))];
            } else {
                $refs = [];
                for ($i = 0; $i < 2; ++$i) {
                    $customer = $this->customer();
                    $this->carts[] = CartFixture::cart($this->control, customerId: $customer, expiresAt: '+180 days', lines: [[$variant, 1]]);
                    $refs[] = CartReference::customer($customer);
                }
            }
            $key = $this->key();

            $outcomes = ConcurrentCommands::run($this->control, array_map(fn (CartReference $ref): PlaceOrder => $this->place($ref, self::GROSS, [[$variant, 1]], $key), $refs));

            $message = 'O7 round '.$round.(1 === $round % 2 ? ' (guests)' : ' (customers)');
            self::assertSame([IdempotencyKeyReused::class => 1, Outcome::SUCCESS => 1], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            $winner = Outcome::SUCCESS === $outcomes[0]->kind ? 0 : 1;
            $loser = 1 - $winner;
            self::assertSame('Ten klucz żądania był już użyty dla innego zamówienia.', $outcomes[$loser]->message, $message);
            self::assertNull($outcomes[$loser]->result, $message.': the refusal carries no order');
            $order = $outcomes[$winner]->result['order'] ?? null;
            self::assertIsArray($order);
            foreach (['orderId', 'number', 'email'] as $field) {
                self::assertIsString($order[$field]);
                self::assertStringNotContainsString($order[$field], (string) $outcomes[$loser]->message, $message);
            }
            self::assertSame(1, $this->orderCount($key), $message);
            self::assertSame([10, 1], OrderingFixture::stock($this->control, $variant), $message.': one reservation');
            self::assertSame([[$variant, 1]], CartFixture::lines($this->control, $this->cartIdOf($refs[$loser])), $message.': the cart of the loser keeps its line');
            self::assertSame([], CartFixture::lines($this->control, $this->cartIdOf($refs[$winner])), $message);
            $this->assertInvariant($message);
        }
    }

    /**
     * O8: the list of the checkout is from before a change of the quantity of its line in another tab. Either the
     * order is placed with the quantity of the list and the late change finds no line, or the change comes first and
     * the placement is refused with the new quantity in the cart; never an order with the new quantity.
     */
    public function testO8APlacementWithTheListFromBeforeAChangeOfTheQuantityNeverOrdersTheNewQuantity(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(10);
            $cart = $this->guestCart([[$variant, 1]]);

            [$placed, $change] = ConcurrentCommands::run($this->control, [
                $this->place(CartReference::guest($cart), self::GROSS, [[$variant, 1]]),
                new ChangeCartItemQuantity(CartRef::guest($cart), $variant, 3),
            ]);

            $message = 'O8 round '.$round;
            if (Outcome::SUCCESS === $placed->kind) {
                self::assertSame(CartLineNotFound::class, $change->kind, $message.': the late change finds the emptied cart: '.$change->message);
                self::assertSame([$variant => 1], OrderingFixture::orderLines($this->control, (string) self::orderIdOf($placed)), $message);
                self::assertSame([], CartFixture::lines($this->control, $cart), $message);
            } else {
                self::assertSame(CartContentChanged::class, $placed->kind, $message.': '.$placed->message);
                self::assertSame(Outcome::SUCCESS, $change->kind, $message.': '.$change->message);
                self::assertSame([[$variant, 3]], CartFixture::lines($this->control, $cart), $message.': the new quantity stays in the cart');
            }
            $this->assertInvariant($message);
        }
    }

    /**
     * O9: another variant is added in another tab while the checkout places the order. Either the order has the lines
     * of the list and the cart only the new line, or the placement is refused and the cart has both; the added line is
     * never ordered and never lost.
     */
    public function testO9APlacementAtOnceWithAnAdditionOfAnotherVariantNeverOrdersTheAddedLine(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(10);
            $added = $this->variant(10);
            $cart = $this->guestCart([[$variant, 1]]);

            [$placed, $addition] = ConcurrentCommands::run($this->control, [
                $this->place(CartReference::guest($cart), self::GROSS, [[$variant, 1]]),
                new AddCartItem(CartRef::guest($cart), $added, 2),
            ]);

            $message = 'O9 round '.$round;
            self::assertSame(Outcome::SUCCESS, $addition->kind, $message.': '.$addition->message);
            if (Outcome::SUCCESS === $placed->kind) {
                self::assertSame([$variant => 1], OrderingFixture::orderLines($this->control, (string) self::orderIdOf($placed)), $message);
                self::assertSame([[$added, 2]], CartFixture::lines($this->control, $cart), $message.': the cart has only the new line');
            } else {
                self::assertSame(CartContentChanged::class, $placed->kind, $message.': '.$placed->message);
                self::assertSame([[$variant, 1], [$added, 2]], CartFixture::lines($this->control, $cart), $message.': the cart has both lines');
            }
            $this->assertInvariant($message);
        }
    }

    /**
     * O10: one of two lines is removed in another tab while the checkout places the order of both. Either the order
     * has both lines and the late removal changes nothing (RemoveCartItem takes a missing line as done), or the
     * placement is refused and the cart has the other line.
     */
    public function testO10APlacementOfTwoLinesAtOnceWithARemovalOfOneOrdersBothOrNone(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $a = $this->variant(10);
            $b = $this->variant(10);
            $cart = $this->guestCart([[$a, 1], [$b, 1]]);

            [$placed, $removal] = ConcurrentCommands::run($this->control, [
                $this->place(CartReference::guest($cart), 2 * self::GROSS, [[$a, 1], [$b, 1]]),
                new RemoveCartItem(CartRef::guest($cart), $b),
            ]);

            $message = 'O10 round '.$round;
            self::assertSame(Outcome::SUCCESS, $removal->kind, $message.': '.$removal->message);
            if (Outcome::SUCCESS === $placed->kind) {
                self::assertSame(self::sorted([$a => 1, $b => 1]), OrderingFixture::orderLines($this->control, (string) self::orderIdOf($placed)), $message);
                self::assertSame([], CartFixture::lines($this->control, $cart), $message);
            } else {
                self::assertSame(CartContentChanged::class, $placed->kind, $message.': '.$placed->message);
                self::assertSame([[$a, 1]], CartFixture::lines($this->control, $cart), $message.': the cart has the other line');
            }
            $this->assertInvariant($message);
        }
    }

    /**
     * O11: two placements with different keys from one cart, one with the current list and one with a stale one.
     * Exactly one order, with the current list; the stale one is refused as changed (before) or empty (after).
     */
    public function testO11TwoPlacementsFromOneCartWithACurrentAndAStaleListGiveOneOrderOfTheCurrentList(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->variant(10);
            $cart = $this->guestCart([[$variant, 2]]);
            $current = $this->place(CartReference::guest($cart), 2 * self::GROSS, [[$variant, 2]]);
            $stale = $this->place(CartReference::guest($cart), self::GROSS, [[$variant, 1]]);
            $odd = 1 === $round % 2;

            $outcomes = ConcurrentCommands::run($this->control, $odd ? [$current, $stale] : [$stale, $current]);

            $message = 'O11 round '.$round;
            [$currentOutcome, $staleOutcome] = $odd ? $outcomes : [$outcomes[1], $outcomes[0]];
            self::assertSame(Outcome::SUCCESS, $currentOutcome->kind, $message.': '.$currentOutcome->message);
            self::assertContains($staleOutcome->kind, [CartContentChanged::class, CartEmpty::class], $message.': '.$staleOutcome->message);
            self::assertSame(1, $this->orderCount($current->idempotencyKey), $message);
            self::assertSame(0, $this->orderCount($stale->idempotencyKey), $message);
            self::assertSame([$variant => 2], OrderingFixture::orderLines($this->control, (string) self::orderIdOf($currentOutcome)), $message);
            self::assertSame([10, 2], OrderingFixture::stock($this->control, $variant), $message.': one reservation');
            $this->assertInvariant($message);
        }
    }

    /**
     * O12: the gap the expected lines close (R1 of docs/features/cart-order-consistency/spec.md). Two variants of the same
     * price; the checkout showed A × 2 and B × 1, and another tab turns the cart into A × 1 and B × 2, the same total.
     * The change takes two commands (B to 2, A to 1) and the state between them has another total, which the total
     * check alone would catch; so in odd rounds B is changed before the round (the cart is A × 2, B × 2 when it starts)
     * and only the change of A runs at once with the placement: the placement sees either the state between or the
     * final state with the same total. Even rounds run both changes at once with the placement from A × 2, B × 1, so
     * the placement can also come first. Whatever runs first, an order has exactly the lines of the list and both
     * changes then find the emptied cart, or the placement is refused as changed and both changes are in the cart;
     * never an order of A × 1 and B × 2.
     */
    public function testO12SwappedQuantitiesAtTheSameTotalInAnotherTabNeverGiveAnOrderOfOtherLines(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $a = $this->variant(10);
            $b = $this->variant(10);
            $odd = 1 === $round % 2;
            $cart = $this->guestCart($odd ? [[$a, 2], [$b, 2]] : [[$a, 2], [$b, 1]]);
            $changes = $odd
                ? [new ChangeCartItemQuantity(CartRef::guest($cart), $a, 1)]
                : [new ChangeCartItemQuantity(CartRef::guest($cart), $a, 1), new ChangeCartItemQuantity(CartRef::guest($cart), $b, 2)];

            $outcomes = ConcurrentCommands::run($this->control, [$this->place(CartReference::guest($cart), 3 * self::GROSS, [[$a, 2], [$b, 1]]), ...$changes]);

            $message = 'O12 round '.$round.($odd ? ' (B changed before, A at once)' : ' (A and B at once)');
            $placed = $outcomes[0];
            $outcomes = \array_slice($outcomes, 1);
            if (Outcome::SUCCESS === $placed->kind) {
                self::assertSame(self::sorted([$a => 2, $b => 1]), OrderingFixture::orderLines($this->control, (string) self::orderIdOf($placed)), $message.': an order of other lines than the list');
            }
            self::assertContains($placed->kind, [Outcome::SUCCESS, CartContentChanged::class], $message.': '.$placed->message);
            if (Outcome::SUCCESS === $placed->kind) {
                foreach ($outcomes as $change) {
                    self::assertSame(CartLineNotFound::class, $change->kind, $message.': a change after the order finds the emptied cart: '.$change->message);
                }
                self::assertSame([], CartFixture::lines($this->control, $cart), $message);
            } else {
                foreach ($outcomes as $change) {
                    self::assertSame(Outcome::SUCCESS, $change->kind, $message.': '.$change->message);
                }
                self::assertSame([[$a, 1], [$b, 2]], CartFixture::lines($this->control, $cart), $message.': the changes are in the cart');
            }
            $this->assertInvariant($message);
        }
    }

    /**
     * The reserved units of every variant of the test are the sum of its active reservations, every order of the
     * test has a reservation with its id as the key and the same lines as the order, and the lines of every order are
     * the `expectedLines` of the request that placed it.
     */
    private function assertInvariant(string $message): void
    {
        foreach ($this->variants as $variant) {
            [, $reserved] = OrderingFixture::stock($this->control, $variant);
            $active = $this->control->fetchOne("SELECT COALESCE(SUM(l.quantity), 0) FROM inventory_reservation_line l JOIN inventory_reservation r ON r.reservation_key = l.reservation_key WHERE r.status = 'active' AND l.variant_id = ?", [$variant]);
            self::assertSame($reserved, $active, $message.': reserved = active reservations of '.$variant);
        }
        if ([] === $this->keys) {
            return;
        }
        foreach ($this->control->fetchFirstColumn('SELECT id FROM ordering_order WHERE idempotency_key IN (?)', [$this->keys], [ArrayParameterType::STRING]) as $orderId) {
            \assert(\is_string($orderId));
            self::assertSame('active', $this->control->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]), $message.': order '.$orderId.' has its reservation');
            self::assertSame(OrderingFixture::orderLines($this->control, $orderId), OrderingFixture::reservation($this->control, $orderId), $message.': with the lines of the order');
            self::assertSame([$this->deliveryMethod, self::DELIVERY], $this->control->fetchNumeric('SELECT delivery_method_id, delivery_gross FROM ordering_order WHERE id = ?', [$orderId]), $message.': order '.$orderId.' has the method and its cost');
            self::assertSame(self::DELIVERY, $this->control->fetchOne('SELECT SUM(gross)::int FROM ordering_order_delivery_vat WHERE order_id = ?', [$orderId]), $message.': split over the VAT rates');
            $key = $this->control->fetchOne('SELECT idempotency_key FROM ordering_order WHERE id = ?', [$orderId]);
            \assert(\is_string($key));
            self::assertSame($this->expected[$key], OrderingFixture::orderLines($this->control, $orderId), $message.': order '.$orderId.' has the lines of its list');
        }
    }

    /**
     * @param int                      $expectedTotalGross of the goods; the cost of the delivery is added
     * @param list<array{string, int}> $expectedLines      the lines the checkout showed: variant id and quantity
     */
    private function place(CartReference $cart, int $expectedTotalGross, array $expectedLines, ?string $key = null): PlaceOrder
    {
        $key ??= $this->key();
        $quantities = [];
        foreach ($expectedLines as [$variant, $quantity]) {
            $quantities[$variant] = $quantity;
        }
        $this->expected[$key] = self::sorted($quantities);

        return new PlaceOrder($cart, 'jan@example.com', 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', $this->deliveryMethod, $expectedTotalGross + self::DELIVERY, OrderingFixture::expected($expectedLines), $key);
    }

    /**
     * @param array<string, int> $lines
     *
     * @return array<string, int>
     */
    private static function sorted(array $lines): array
    {
        ksort($lines);

        return $lines;
    }

    private function key(): string
    {
        $key = 'concurrency-'.Uuid::v7()->toRfc4122();
        $this->keys[] = $key;

        return $key;
    }

    private function variant(int $stock): string
    {
        [$variant, $product] = OrderingFixture::variant($this->control, 1_000, $stock);
        $this->variants[] = $variant;
        $this->products[] = $product;

        return $variant;
    }

    /**
     * @param list<array{string, int}> $lines
     */
    private function guestCart(array $lines): string
    {
        $cart = CartFixture::cart($this->control, expiresAt: '+30 days', lines: $lines);
        $this->carts[] = $cart;

        return $cart;
    }

    private function customer(): string
    {
        $customer = 'ordering-concurrency-'.Uuid::v7()->toRfc4122();
        $this->customers[] = $customer;

        return $customer;
    }

    private function customerCart(string $customer): string
    {
        $id = $this->control->fetchOne('SELECT id FROM cart_cart WHERE customer_id = ?', [$customer]);
        \assert(\is_string($id));

        return $id;
    }

    private function cartIdOf(CartReference $ref): string
    {
        return null !== $ref->customerId ? $this->customerCart($ref->customerId) : (string) $ref->cartId;
    }

    private function orderCount(string $key): int
    {
        return OrderingFixture::count($this->control, 'SELECT COUNT(*) FROM ordering_order WHERE idempotency_key = ?', [$key]);
    }

    /**
     * @param list<Outcome> $outcomes successes of PlaceOrder
     *
     * @return array<int, int> count by replayed (0: placed, 1: replayed)
     */
    private static function replays(array $outcomes): array
    {
        $replays = array_count_values(array_map(static fn (Outcome $o): int => match ($o->result['replayed'] ?? null) {
            true => 1,
            false => 0,
            default => -1,
        }, $outcomes));
        ksort($replays);

        return $replays;
    }

    /**
     * @param list<Outcome> $outcomes
     *
     * @return list<string>
     */
    private static function orderIds(array $outcomes): array
    {
        return array_map(static fn (Outcome $o): string => self::orderIdOf($o) ?? '', $outcomes);
    }

    private static function orderIdOf(Outcome $outcome): ?string
    {
        $order = $outcome->result['order'] ?? null;
        $orderId = \is_array($order) ? ($order['orderId'] ?? null) : null;

        return \is_string($orderId) ? $orderId : null;
    }

    /**
     * @param list<Outcome> $outcomes
     *
     * @return array<string, int> outcome kind => count, by kind
     */
    private static function kinds(array $outcomes): array
    {
        $kinds = array_count_values(array_map(static fn (Outcome $o): string => $o->kind, $outcomes));
        ksort($kinds);

        return $kinds;
    }

    /**
     * @param list<Outcome> $outcomes
     */
    private static function messages(array $outcomes): string
    {
        return implode('; ', array_filter(array_map(static fn (Outcome $o): ?string => $o->message, $outcomes)));
    }
}
