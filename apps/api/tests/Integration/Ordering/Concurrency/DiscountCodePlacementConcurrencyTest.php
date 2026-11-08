<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Concurrency;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Domain\DiscountCodeRejected;
use App\Pricing\Application\Command\ReviseDiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountKind;
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
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

/**
 * The concurrency of discount codes in placements (DISCOUNT-CODES, "Testy – Współbieżność") on PostgreSQL: every
 * participant is a process of its own dispatching on command.bus (ConcurrentCommands), all start at once, 5 rounds on
 * fresh codes, variants, carts and keys. After every round the usages of each code of the test are exactly the orders
 * placed with it (an active usage per order not cancelled), and no participant ends in a deadlock or a timeout. The
 * rows commit, so they are deleted after the test.
 */
final class DiscountCodePlacementConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;
    /** 1 000 net + 23 % VAT, the price of every variant of the test. */
    private const int GROSS = 1_230;
    /** 10 % of GROSS. */
    private const int DISCOUNT = 123;

    private Connection $control;
    /** @var list<string> */
    private array $products = [];
    /** @var list<string> */
    private array $variants = [];
    /** @var list<string> */
    private array $carts = [];
    /** @var list<string> */
    private array $keys = [];
    /** @var list<string> */
    private array $codes = [];

    protected function setUp(): void
    {
        // Boots the kernel, so its cache is warm before the participants boot theirs.
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        $this->control = $this->connection();
    }

    protected function tearDown(): void
    {
        if ([] !== $this->keys) {
            $orderIds = $this->control->fetchFirstColumn('SELECT id FROM ordering_order WHERE idempotency_key IN (?)', [$this->keys], [ArrayParameterType::STRING]);
            OrderingFixture::deleteOrders($this->control, array_values(array_filter($orderIds, is_string(...))));
        }
        if ([] !== $this->codes) {
            $this->control->executeStatement('DELETE FROM pricing_discount_code_usage WHERE code_id IN (?)', [$this->codes], [ArrayParameterType::STRING]);
            $this->control->executeStatement('DELETE FROM pricing_discount_code WHERE id IN (?)', [$this->codes], [ArrayParameterType::STRING]);
        }
        CartFixture::delete($this->control, $this->carts, []);
        if ([] !== $this->variants) {
            $this->control->executeStatement('DELETE FROM pricing_variant_price WHERE variant_id IN (?)', [$this->variants], [ArrayParameterType::STRING]);
        }
        StockFixture::delete($this->control, $this->variants, []);
        CatalogFixture::delete($this->control, $this->products);
        $this->control->close();
        parent::tearDown();
    }

    public function testTwoPlacementsForTheLastFreeUsageOfALimitedCodeGiveOneOrderAndOneUsedUp(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$codeId, $text] = $this->code(usageLimit: 2);
            $variant = $this->variant(10);
            // One usage of the limit is taken already: a placement with the code before this round.
            $outcomes = ConcurrentCommands::run($this->control, [$this->place($this->guestCart([[$variant, 1]]), self::GROSS - self::DISCOUNT, $text)]);
            self::assertSame([Outcome::SUCCESS => 1], self::kinds($outcomes), self::messages($outcomes));
            $carts = [$this->guestCart([[$variant, 1]]), $this->guestCart([[$variant, 1]])];

            $outcomes = ConcurrentCommands::run($this->control, array_map(fn (string $cart): PlaceOrder => $this->place($cart, self::GROSS - self::DISCOUNT, $text), $carts));

            $message = 'C1 round '.$round;
            self::assertSame([DiscountCodeRejected::class => 1, Outcome::SUCCESS => 1], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            $loser = Outcome::SUCCESS === $outcomes[0]->kind ? 1 : 0;
            self::assertSame('Ten kod został już wykorzystany.', $outcomes[$loser]->message, $message);
            self::assertSame([[$variant, 1]], CartFixture::lines($this->control, $carts[$loser]), $message.': the cart of the loser keeps its line');
            self::assertSame(2, $this->activeUses($codeId), $message.': the limit is never exceeded');
            self::assertSame([10, 2], OrderingFixture::stock($this->control, $variant), $message.': two reservations, none of the loser');
            $this->assertUsagesAreTheOrders($message);
        }
    }

    public function testDisablingAnUnlimitedCodeWhilePlacingEndsEachPlacementWithAnOrderAndAUsageOrInvalidWithoutWaiting(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$codeId, $text] = $this->code(usageLimit: null);
            $variant = $this->variant(20);
            $commands = [];
            $carts = [];
            for ($i = 0; $i < 6; ++$i) {
                $carts[] = $cart = $this->guestCart([[$variant, 1]]);
                $commands[] = $this->place($cart, self::GROSS - self::DISCOUNT, $text);
            }
            $disable = $this->disable($codeId);
            array_splice($commands, 3, 0, [$disable]);

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'C2 round '.$round;
            self::assertSame(Outcome::SUCCESS, $outcomes[3]->kind, $message.': the change of the code: '.$outcomes[3]->message);
            unset($outcomes[3]);
            $placements = array_values($outcomes);
            foreach ($placements as $index => $outcome) {
                self::assertContains($outcome->kind, [Outcome::SUCCESS, DiscountCodeRejected::class], $message.': no deadlock, no timeout: '.$outcome->message);
                if (DiscountCodeRejected::class === $outcome->kind) {
                    self::assertSame('Ten kod jest nieprawidłowy albo nieaktywny.', $outcome->message, $message);
                    self::assertSame([[$variant, 1]], CartFixture::lines($this->control, $carts[$index]), $message.': a refused placement keeps its cart');
                }
            }
            $placed = \count(array_filter($placements, static fn (Outcome $o): bool => Outcome::SUCCESS === $o->kind));
            self::assertSame($placed, $this->activeUses($codeId), $message.': usages = orders with the code');
            self::assertSame($placed, $this->control->fetchOne('SELECT COUNT(*) FROM ordering_order WHERE discount_code = ? AND idempotency_key IN (?)', [$text, $this->keys], [ParameterType::STRING, ArrayParameterType::STRING]), $message);
            self::assertFalse($this->control->fetchOne('SELECT enabled FROM pricing_discount_code WHERE id = ?', [$codeId]), $message);
            $this->assertUsagesAreTheOrders($message);
        }
    }

    public function testAChangeOfAnUnlimitedCodeDoesNotWaitForAPlacementThatIsWritingItsUsage(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$codeId] = $this->code(usageLimit: null);
            // A placement in progress: its usage is written (the foreign key holds FOR KEY SHARE on the row of the
            // code) and its transaction is not committed.
            $placement = $this->connection();
            $placement->beginTransaction();
            $placement->executeStatement('INSERT INTO pricing_discount_code_usage (id, code_id, order_id, used_at) VALUES (?, ?, ?, now())', [Uuid::v7()->toRfc4122(), $codeId, Uuid::v7()->toRfc4122()]);

            $started = microtime(true);
            $outcomes = ConcurrentCommands::run($this->control, [$this->disable($codeId)]);
            $elapsed = microtime(true) - $started;
            $placement->rollBack();
            $placement->close();

            $message = 'C3 round '.$round;
            self::assertSame([Outcome::SUCCESS => 1], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            self::assertLessThan(20.0, $elapsed, $message.': the change did not wait for the placement (a wait ends at the statement timeout of 30 s)');
            self::assertFalse($this->control->fetchOne('SELECT enabled FROM pricing_discount_code WHERE id = ?', [$codeId]), $message);
        }
    }

    public function testAnExpiryAndAPlacementWithTheSameCodeDoNotDeadlock(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$codeId, $text] = $this->code(usageLimit: 5);
            $variant = $this->variant(10);
            $first = ConcurrentCommands::run($this->control, [$this->place($this->guestCart([[$variant, 2]]), 2 * (self::GROSS - self::DISCOUNT), $text)]);
            self::assertSame([Outcome::SUCCESS => 1], self::kinds($first), self::messages($first));
            $expired = self::orderIdOf($first[0]);
            $this->control->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '10 minutes' WHERE id = ?", [$expired]);
            $cart = $this->guestCart([[$variant, 3]]);

            // The message as the worker hands it to the bus: received from `scheduled`, so it is handled, not sent.
            $outcomes = ConcurrentCommands::run($this->control, [
                new Envelope(new ExpireUnpaidOrder($expired), [new ReceivedStamp('scheduled')]),
                $this->place($cart, 3 * self::GROSS - 369, $text),
            ]);

            $message = 'C4 round '.$round;
            self::assertSame([Outcome::SUCCESS => 2], self::kinds($outcomes), $message.': no deadlock: '.self::messages($outcomes));
            self::assertSame('cancelled', $this->control->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$expired]), $message);
            self::assertNotFalse($this->control->fetchOne('SELECT released_at FROM pricing_discount_code_usage WHERE order_id = ?', [$expired]), $message);
            self::assertNotNull($this->control->fetchOne('SELECT released_at FROM pricing_discount_code_usage WHERE order_id = ?', [$expired]), $message.': the usage of the expired order is released');
            self::assertSame(1, $this->activeUses($codeId), $message.': the usage of the new order only');
            self::assertSame([10, 3], OrderingFixture::stock($this->control, $variant), $message.': the expired reservation released, the new one made');
            $this->assertUsagesAreTheOrders($message);
        }
    }

    /**
     * Every usage of a code of the test belongs to an order placed with that code, active exactly while the order is
     * not cancelled, and every order with a code has a usage.
     */
    private function assertUsagesAreTheOrders(string $message): void
    {
        if ([] === $this->keys) {
            return;
        }
        $orders = $this->control->fetchAllAssociative(
            'SELECT o.id, o.status, o.discount_code, u.released_at IS NULL AS active, c.code AS used_code FROM ordering_order o LEFT JOIN pricing_discount_code_usage u ON u.order_id = o.id LEFT JOIN pricing_discount_code c ON c.id = u.code_id WHERE o.idempotency_key IN (?)',
            [$this->keys],
            [ArrayParameterType::STRING],
        );
        foreach ($orders as $order) {
            self::assertSame($order['discount_code'], $order['used_code'], $message.': the order '.$order['id'].' has the usage of its code');
            self::assertSame('cancelled' !== $order['status'], $order['active'], $message.': the usage of '.$order['id'].' is active while the order is not cancelled');
        }
        self::assertSame(0, $this->control->fetchOne(
            'SELECT COUNT(*) FROM pricing_discount_code_usage u WHERE u.code_id IN (?) AND NOT EXISTS (SELECT 1 FROM ordering_order o WHERE o.id = u.order_id)',
            [$this->codes],
            [ArrayParameterType::STRING],
        ), $message.': no usage without an order');
    }

    /**
     * A 10 % code started a day ago, without a minimum or an end, with the limit given.
     *
     * @return array{string, string} the id and the text
     */
    private function code(?int $usageLimit): array
    {
        $id = Uuid::v7()->toRfc4122();
        $text = 'C'.strtoupper(bin2hex(random_bytes(6)));
        $this->control->executeStatement(
            "INSERT INTO pricing_discount_code (id, code, kind, percent_off, starts_at, usage_limit, enabled, created_at, updated_at, version) VALUES (?, ?, 'percent', 10, now() - interval '1 day', ?, true, now(), now(), 1)",
            [$id, $text, $usageLimit],
        );
        $this->codes[] = $id;

        return [$id, $text];
    }

    private function disable(string $codeId): ReviseDiscountCode
    {
        $startsAt = $this->control->fetchOne('SELECT starts_at FROM pricing_discount_code WHERE id = ?', [$codeId]);
        \assert(\is_string($startsAt));

        return new ReviseDiscountCode($codeId, 1, DiscountKind::Percent, 10, null, new \DateTimeImmutable($startsAt), null, null, false, 'kc-staff', 'marketing');
    }

    private function place(string $cart, int $expectedTotalGross, ?string $code): PlaceOrder
    {
        $key = 'concurrency-'.Uuid::v7()->toRfc4122();
        $this->keys[] = $key;

        return new PlaceOrder(CartReference::guest($cart), 'jan@example.com', 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', $expectedTotalGross, $key, $code);
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

    private function activeUses(string $codeId): int
    {
        return OrderingFixture::count($this->control, 'SELECT COUNT(*) FROM pricing_discount_code_usage WHERE code_id = ? AND released_at IS NULL', [$codeId]);
    }

    private function connection(): Connection
    {
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $connection = DriverManager::getConnection($params, new DbalConfiguration());
        $connection->executeStatement("SET lock_timeout = '10s'");

        return $connection;
    }

    private static function orderIdOf(Outcome $outcome): string
    {
        $order = $outcome->result['order'] ?? null;
        $orderId = \is_array($order) ? ($order['orderId'] ?? null) : null;
        \assert(\is_string($orderId));

        return $orderId;
    }

    /**
     * @param array<int, Outcome> $outcomes
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
     * @param array<int, Outcome> $outcomes
     */
    private static function messages(array $outcomes): string
    {
        return implode(' | ', array_map(static fn (Outcome $o): string => $o->kind.': '.$o->message, $outcomes));
    }
}
