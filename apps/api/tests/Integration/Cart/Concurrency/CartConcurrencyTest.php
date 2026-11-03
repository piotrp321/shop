<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cart\Concurrency;

use App\Cart\Application\CartRef;
use App\Cart\Application\Command\AddCartItem;
use App\Cart\Application\Command\MergeGuestCart;
use App\Cart\Domain\CartLineLimit;
use App\Cart\Domain\GuestCartMerged;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Inventory\CatalogFixture;
use App\Tests\Integration\Inventory\Concurrency\ConcurrentCommands;
use App\Tests\Integration\Inventory\Concurrency\Outcome;
use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Scenarios K1 to K7 of the Cart specification ("Testy – Współbieżność") on PostgreSQL: every participant is
 * a process of its own dispatching on command.bus (ConcurrentCommands of the Inventory tests), all start at once.
 * Each scenario runs 5 rounds on fresh products and customers; after every round no cart of the test has more than
 * 50 lines or a line of more than 99 units, and no merged cart has lines. The rows commit (products in Catalog,
 * prices, stocks, carts), so they are deleted after the test.
 */
final class CartConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;

    private Connection $control;
    /** @var list<string> */
    private array $products = [];
    /** @var list<string> */
    private array $customers = [];
    /** @var list<string> */
    private array $carts = [];

    protected function setUp(): void
    {
        // Boots the kernel, so its cache is warm before the participants boot theirs.
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $this->control->executeStatement("SET lock_timeout = '10s'");
    }

    protected function tearDown(): void
    {
        CartFixture::delete($this->control, $this->carts, $this->customers);
        if ([] !== $this->products) {
            $this->control->executeStatement('DELETE FROM pricing_product_price WHERE product_id IN (?)', [$this->products], [ArrayParameterType::STRING]);
        }
        StockFixture::delete($this->control, $this->products, []);
        CatalogFixture::delete($this->control, $this->products);
        $this->control->close();
        parent::tearDown();
    }

    public function testK1TenMergesOfOneGuestIntoOneCustomerAtOnceMoveTheLinesOnce(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customer = $this->customer();
            $p1 = Uuid::v7()->toRfc4122();
            $p2 = Uuid::v7()->toRfc4122();
            $this->carts[] = CartFixture::cart($this->control, customerId: $customer, expiresAt: '+180 days', lines: [[$p1, 1]]);
            $this->carts[] = $guest = CartFixture::cart($this->control, expiresAt: '+30 days', lines: [[$p1, 2], [$p2, 3]]);
            $commands = array_fill(0, 10, new MergeGuestCart($customer, $guest));

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'K1 round '.$round;
            self::assertSame([Outcome::SUCCESS => 10], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            self::assertSame(['already_merged' => 9, 'merged' => 1], self::mergeResults($outcomes), $message);
            self::assertEqualsCanonicalizing([$p1 => 3, $p2 => 3], $this->customerLines($customer), $message.': the lines moved once');
            $this->assertMergedInto($guest, $customer, $message);
            $this->assertInvariant($message);
        }
    }

    public function testK2AMergeAtOnceWithTenAdditionsToTheGuestCartKeepsEverySuccessfulAddition(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customer = $this->customer();
            $product = $this->purchasableProduct();
            $this->carts[] = $guest = CartFixture::cart($this->control, expiresAt: '+30 days', lines: [[$product, 1]]);
            $commands = [new MergeGuestCart($customer, $guest)];
            for ($i = 0; $i < 10; ++$i) {
                $commands[] = new AddCartItem(CartRef::guest($guest), $product, 1);
            }

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'K2 round '.$round;
            $merge = array_shift($outcomes);
            self::assertSame(Outcome::SUCCESS, $merge->kind, $message.': '.$merge->message);
            self::assertSame('merged', $merge->result['result'] ?? null, $message);
            $additions = self::kinds($outcomes);
            $successes = $additions[Outcome::SUCCESS] ?? 0;
            self::assertSame(10, $successes + ($additions[GuestCartMerged::class] ?? 0), $message.': every addition succeeded or found the cart merged; '.self::messages($outcomes));
            self::assertSame([$product => 1 + $successes], $this->customerLines($customer), $message.': every successful addition is in the customer cart');
            $this->assertMergedInto($guest, $customer, $message);
            self::assertSame(1, $this->control->fetchOne('SELECT COUNT(*) FROM cart_cart WHERE id = ? AND updated_at = merged_at', [$guest]), $message.': the guest cart did not change after the merge');
            $this->assertInvariant($message);
        }
    }

    public function testK5OneGuestMergedAtOnceIntoTwoCustomersGoesToExactlyOne(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $first = $this->customer();
            $second = $this->customer();
            $product = Uuid::v7()->toRfc4122();
            $this->carts[] = $guest = CartFixture::cart($this->control, expiresAt: '+30 days', lines: [[$product, 2]]);

            $outcomes = ConcurrentCommands::run($this->control, [new MergeGuestCart($first, $guest), new MergeGuestCart($second, $guest)]);

            $message = 'K5 round '.$round;
            self::assertSame([Outcome::SUCCESS => 2], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            self::assertSame(['already_merged' => 1, 'merged' => 1], self::mergeResults($outcomes), $message);
            [$winner, $loser] = 'merged' === ($outcomes[0]->result['result'] ?? null) ? [$first, $second] : [$second, $first];
            self::assertSame([$product => 2], $this->customerLines($winner), $message);
            self::assertSame([], $this->customerLines($loser), $message);
            self::assertSame(0, $this->control->fetchOne('SELECT COUNT(*) FROM cart_cart WHERE customer_id = ?', [$loser]), $message.': already_merged creates no cart');
            $this->assertMergedInto($guest, $winner, $message);
            $this->assertInvariant($message);
        }
    }

    public function testK6TwoGuestsMergedAtOnceIntoOneCustomerAreBothMerged(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customer = $this->customer();
            $p1 = Uuid::v7()->toRfc4122();
            $p2 = Uuid::v7()->toRfc4122();
            $p3 = Uuid::v7()->toRfc4122();
            $this->carts[] = $first = CartFixture::cart($this->control, expiresAt: '+30 days', lines: [[$p1, 2], [$p2, 1]]);
            $this->carts[] = $second = CartFixture::cart($this->control, expiresAt: '+30 days', lines: [[$p1, 3], [$p3, 4]]);

            // The customer has no cart yet: both merges also race to create it.
            $outcomes = ConcurrentCommands::run($this->control, [new MergeGuestCart($customer, $first), new MergeGuestCart($customer, $second)]);

            $message = 'K6 round '.$round;
            self::assertSame([Outcome::SUCCESS => 2], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            self::assertSame(['merged' => 2], self::mergeResults($outcomes), $message);
            self::assertSame(1, $this->control->fetchOne('SELECT COUNT(*) FROM cart_cart WHERE customer_id = ?', [$customer]), $message.': one cart');
            self::assertEqualsCanonicalizing([$p1 => 5, $p2 => 1, $p3 => 4], $this->customerLines($customer), $message.': the quantities are summed');
            $this->assertMergedInto($first, $customer, $message);
            $this->assertMergedInto($second, $customer, $message);
            $this->assertInvariant($message);
        }
    }

    public function testK3TwentyAdditionsAtOnceToACustomerCartAreAllStored(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customer = $this->customer();
            $this->carts[] = CartFixture::cart($this->control, customerId: $customer, expiresAt: '+180 days');
            $common = $this->purchasableProduct();
            $commands = [];
            $distinct = [];
            for ($i = 0; $i < 10; ++$i) {
                $commands[] = new AddCartItem(CartRef::customer($customer), $common, 1);
                $distinct[] = $product = $this->purchasableProduct();
                $commands[] = new AddCartItem(CartRef::customer($customer), $product, 1);
            }

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'K3 round '.$round;
            self::assertSame([Outcome::SUCCESS => 20], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            $lines = $this->customerLines($customer);
            self::assertSame(10, $lines[$common] ?? null, $message.': the common product has every unit');
            foreach ($distinct as $product) {
                self::assertSame(1, $lines[$product] ?? null, $message.': every other product is stored');
            }
            self::assertCount(11, $lines, $message);
            $this->assertInvariant($message);
        }
    }

    public function testK4TwoFirstAdditionsOfACustomerAtOnceMakeOneCartWithBothLines(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $customer = $this->customer();
            $a = $this->purchasableProduct();
            $b = $this->purchasableProduct();

            $outcomes = ConcurrentCommands::run($this->control, [
                new AddCartItem(CartRef::customer($customer), $a, 1),
                new AddCartItem(CartRef::customer($customer), $b, 2),
            ]);

            $message = 'K4 round '.$round;
            self::assertSame([Outcome::SUCCESS => 2], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            self::assertSame(1, $this->control->fetchOne('SELECT COUNT(*) FROM cart_cart WHERE customer_id = ?', [$customer]), $message.': one cart');
            self::assertEqualsCanonicalizing([$a => 1, $b => 2], $this->customerLines($customer), $message);
            $this->assertInvariant($message);
        }
    }

    public function testK7AdditionsOfDifferentProductsAtOnceToACartOf48LinesGiveExactlyTwoSuccesses(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $existing = [];
            for ($i = 0; $i < 48; ++$i) {
                $existing[] = [Uuid::v7()->toRfc4122(), 1];
            }
            $cart = CartFixture::cart($this->control, expiresAt: '+30 days', lines: $existing);
            $this->carts[] = $cart;
            $commands = [];
            for ($i = 0; $i < 10; ++$i) {
                $commands[] = new AddCartItem(CartRef::guest($cart), $this->purchasableProduct(), 1);
            }

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $message = 'K7 round '.$round;
            self::assertSame([CartLineLimit::class => 8, Outcome::SUCCESS => 2], self::kinds($outcomes), $message.': '.self::messages($outcomes));
            self::assertSame(50, $this->control->fetchOne('SELECT COUNT(*) FROM cart_line WHERE cart_id = ?', [$cart]), $message);
            $this->assertInvariant($message);
        }
    }

    /**
     * The guest cart is merged into the cart of the customer and has no lines.
     */
    private function assertMergedInto(string $guest, string $customer, string $message): void
    {
        $row = $this->control->fetchAssociative('SELECT c.status, c.merged_at, m.customer_id FROM cart_cart c LEFT JOIN cart_cart m ON m.id = c.merged_into WHERE c.id = ?', [$guest]);
        self::assertIsArray($row, $message);
        self::assertSame('merged', $row['status'], $message.': the guest cart is merged');
        self::assertNotNull($row['merged_at'], $message);
        self::assertSame($customer, $row['customer_id'], $message.': into the cart of the customer');
        self::assertSame([], CartFixture::lines($this->control, $guest), $message.': the guest cart has no lines');
    }

    /**
     * @param list<Outcome> $outcomes successes of MergeGuestCart
     *
     * @return array<string, int> result => count, by result
     */
    private static function mergeResults(array $outcomes): array
    {
        $results = array_count_values(array_map(static fn (Outcome $o): string => \is_string($o->result['result'] ?? null) ? $o->result['result'] : 'none', $outcomes));
        ksort($results);

        return $results;
    }

    /**
     * No cart of the test has more than 50 lines or a line above 99 units, and no merged cart has lines.
     */
    private function assertInvariant(string $message): void
    {
        $ids = $this->control->fetchFirstColumn('SELECT id FROM cart_cart WHERE id IN (?) OR customer_id IN (?)', [[...$this->carts, '00000000-0000-0000-0000-000000000000'], [...$this->customers, '']], [ArrayParameterType::STRING, ArrayParameterType::STRING]);
        if ([] === $ids) {
            return;
        }
        $types = [ArrayParameterType::STRING];
        self::assertLessThanOrEqual(50, $this->control->fetchOne('SELECT COALESCE(MAX(n), 0) FROM (SELECT COUNT(*) AS n FROM cart_line WHERE cart_id IN (?) GROUP BY cart_id) counts', [$ids], $types), $message.': at most 50 lines');
        self::assertLessThanOrEqual(99, $this->control->fetchOne('SELECT COALESCE(MAX(quantity), 0) FROM cart_line WHERE cart_id IN (?)', [$ids], $types), $message.': at most 99 units');
        self::assertSame(0, $this->control->fetchOne("SELECT COUNT(*) FROM cart_line l JOIN cart_cart c ON c.id = l.cart_id WHERE c.status = 'merged' AND c.id IN (?)", [$ids], $types), $message.': a merged cart has no lines');
    }

    /**
     * A product in Catalog with a current price and 1000 units in stock, committed.
     */
    private function purchasableProduct(): string
    {
        $id = CatalogFixture::product($this->control);
        $this->products[] = $id;
        $this->control->executeStatement(
            "INSERT INTO pricing_product_price (product_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version, updated_at) VALUES (?, 'PLN', 1230, 1000, 230, 2300, 'standard', 1, now())",
            [$id],
        );
        StockFixture::stock($this->control, 1000, productId: $id);

        return $id;
    }

    private function customer(): string
    {
        $customer = 'concurrency-'.Uuid::v7()->toRfc4122();
        $this->customers[] = $customer;

        return $customer;
    }

    /**
     * @return array<string, int> quantity by product
     */
    private function customerLines(string $customer): array
    {
        $rows = $this->control->fetchAllKeyValue('SELECT l.product_id, l.quantity FROM cart_line l JOIN cart_cart c ON c.id = l.cart_id WHERE c.customer_id = ?', [$customer]);
        $lines = [];
        foreach ($rows as $product => $quantity) {
            \assert(\is_int($quantity));
            $lines[(string) $product] = $quantity;
        }

        return $lines;
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
