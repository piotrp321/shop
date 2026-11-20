<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Concurrency;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Domain\TooManyUnpaidOrders;
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
 * The limit of unpaid orders under concurrent placements on PostgreSQL (ABUSE-LIMITS, L6, D8): every participant is
 * a process of its own dispatching PlaceOrder on command.bus from a cart of its own (ConcurrentCommands), all start
 * at once. With the limits of when@test (20 per address and per identity), PARTICIPANTS placements from one address
 * with different emails, or with one email from different addresses, give exactly 20 orders: the count runs under
 * the advisory locks of the address and of the identity. The rows commit, so they are deleted after the test.
 */
final class UnpaidOrderLimitConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 2;
    private const int LIMIT = 20;
    private const int PARTICIPANTS = 24;
    /** 1 000 net + 23 % VAT, the price of every variant of the test. */
    private const int GROSS = 1_230;

    private Connection $control;
    /** @var list<string> */
    private array $products = [];
    /** @var list<string> */
    private array $variants = [];
    /** @var list<string> */
    private array $carts = [];
    /** @var list<string> */
    private array $keys = [];

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
        if ([] !== $this->keys) {
            $orderIds = $this->control->fetchFirstColumn('SELECT id FROM ordering_order WHERE idempotency_key IN (?)', [$this->keys], [ArrayParameterType::STRING]);
            OrderingFixture::deleteOrders($this->control, array_values(array_filter($orderIds, is_string(...))));
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

    public function testPlacementsFromOneAddressWithDifferentEmailsGiveExactlyTheLimitOfTheAddress(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $address = self::address();
            $variant = $this->variant();
            $commands = [];
            for ($i = 0; $i < self::PARTICIPANTS; ++$i) {
                $commands[] = $this->place($variant, 'buyer-'.$i.'-'.bin2hex(random_bytes(4)).'@example.com', $address);
            }

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $this->assertExactlyTheLimit($outcomes, $variant, 'address round '.$round);
            self::assertSame(self::LIMIT, OrderingFixture::count($this->control, 'SELECT COUNT(*) FROM ordering_unpaid_order_claim WHERE address_key = ?', [$address]));
        }
    }

    public function testPlacementsWithOneEmailFromDifferentAddressesGiveExactlyTheLimitOfTheEmail(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $email = 'one-buyer-'.bin2hex(random_bytes(4)).'@example.com';
            $variant = $this->variant();
            $commands = [];
            for ($i = 0; $i < self::PARTICIPANTS; ++$i) {
                // Every form of the email counts to it.
                $commands[] = $this->place($variant, 0 === $i % 2 ? $email : strtoupper(str_replace('@', '+'.$i.'@', $email)), self::address());
            }

            $outcomes = ConcurrentCommands::run($this->control, $commands);

            $this->assertExactlyTheLimit($outcomes, $variant, 'email round '.$round);
            self::assertSame(self::LIMIT, OrderingFixture::count($this->control, 'SELECT COUNT(*) FROM ordering_unpaid_order_claim WHERE identity_key = ?', ['email:'.$email]));
        }
    }

    /**
     * @param list<Outcome> $outcomes
     */
    private function assertExactlyTheLimit(array $outcomes, string $variant, string $message): void
    {
        $kinds = [];
        foreach ($outcomes as $outcome) {
            $kinds[$outcome->kind] = ($kinds[$outcome->kind] ?? 0) + 1;
        }
        ksort($kinds);
        $messages = implode('; ', array_unique(array_map(static fn (Outcome $outcome): string => $outcome->kind.': '.$outcome->message, array_filter($outcomes, static fn (Outcome $outcome): bool => Outcome::SUCCESS !== $outcome->kind))));

        $expected = [Outcome::SUCCESS => self::LIMIT, TooManyUnpaidOrders::class => self::PARTICIPANTS - self::LIMIT];
        ksort($expected);
        self::assertSame($expected, $kinds, $message.': '.$messages);
        self::assertSame([100, self::LIMIT], OrderingFixture::stock($this->control, $variant), $message.': only the orders placed reserve');
    }

    private function place(string $variant, string $email, string $address): PlaceOrder
    {
        $cart = CartFixture::cart($this->control, expiresAt: '+30 days', lines: [[$variant, 1]]);
        $this->carts[] = $cart;
        $key = 'unpaid-limit-'.Uuid::v7()->toRfc4122();
        $this->keys[] = $key;

        return new PlaceOrder(CartReference::guest($cart), $email, 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', OrderingFixture::freeDeliveryMethod($this->control), self::GROSS, $key, null, $address);
    }

    private function variant(): string
    {
        [$variant, $product] = OrderingFixture::variant($this->control, 1_000, 100);
        $this->variants[] = $variant;
        $this->products[] = $product;

        return $variant;
    }

    /** An address of its own for every round (a documentation range, unknown to the other tests). */
    private static function address(): string
    {
        return \sprintf('2001:db8:%x:%x::/64', random_int(0, 0xFFFF), random_int(0, 0xFFFF));
    }
}
