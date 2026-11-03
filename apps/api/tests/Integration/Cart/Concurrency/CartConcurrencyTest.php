<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cart\Concurrency;

use App\Cart\Application\CartRef;
use App\Cart\Application\Command\AddCartItem;
use App\Cart\Domain\CartLineLimit;
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
 * Scenarios K3, K4 and K7 of the Cart specification ("Testy – Współbieżność") on PostgreSQL: every participant is
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
