<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment\Concurrency;

use App\Payment\Application\Command\StartPayment;
use App\Payment\Application\PaymentAccess;
use App\Payment\Domain\PaymentInProgress;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Inventory\CatalogFixture;
use App\Tests\Integration\Inventory\Concurrency\ConcurrentCommands;
use App\Tests\Integration\Inventory\Concurrency\Outcome;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Integration\Payment\PaymentFixture;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Scenarios W4 and W5 of the Payment specification ("Współbieżność") on PostgreSQL: every participant is a process
 * of its own dispatching StartPayment on command.bus (ConcurrentCommands), all start at once; each calls the
 * simulator through KernelForwardingHttpClient on its own connection. Each scenario runs 5 rounds on a fresh order.
 * The rows commit, so they are deleted after the test.
 */
final class StartPaymentConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;

    private Connection $control;
    /** @var list<string> */
    private array $orders = [];
    /** @var list<string> */
    private array $variants = [];
    /** @var list<string> */
    private array $products = [];
    /** @var list<string> */
    private array $carts = [];

    protected function setUp(): void
    {
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $this->control->executeStatement("SET lock_timeout = '10s'");
    }

    protected function tearDown(): void
    {
        PaymentFixture::deleteOrders($this->control, $this->orders);
        CartFixture::delete($this->control, $this->carts);
        if ([] !== $this->variants) {
            $this->control->executeStatement('DELETE FROM pricing_variant_price WHERE variant_id IN (?)', [$this->variants], [ArrayParameterType::STRING]);
        }
        StockFixture::delete($this->control, $this->variants, []);
        CatalogFixture::delete($this->control, $this->products);
        $this->control->close();
        parent::tearDown();
    }

    public function testW4TenStartsWithOneKeyGiveOnePayment(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$orderId, $token] = $this->order();
            $command = new StartPayment($orderId, PaymentAccess::guest($token), 'demo-transfer', 'w4-'.Uuid::v7()->toRfc4122());

            $outcomes = ConcurrentCommands::run($this->control, array_fill(0, 10, $command));

            $message = 'W4 round '.$round.': '.self::messages($outcomes);
            $kinds = self::kinds($outcomes);
            self::assertSame([], array_diff(array_keys($kinds), [Outcome::SUCCESS, PaymentInProgress::class]), $message.': only payments, replays or 409');
            self::assertGreaterThanOrEqual(1, $kinds[Outcome::SUCCESS] ?? 0, $message);
            self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM payment_payment WHERE order_id = ?', $orderId), $message.': one payment');
            self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM payment_payment WHERE order_id = ? AND status = 'pending' AND provider_payment_id IS NOT NULL", $orderId), $message.': pending with its session');
            $started = array_values(array_filter($outcomes, static fn (Outcome $o): bool => Outcome::SUCCESS === $o->kind));
            self::assertSame(1, \count(array_filter($started, static fn (Outcome $o): bool => false === ($o->result['replayed'] ?? null))), $message.': one start, the others replays');
            $ids = array_unique(array_map(static function (Outcome $o): string {
                $payment = $o->result['payment'] ?? null;

                return \is_array($payment) && \is_string($payment['paymentId'] ?? null) ? $payment['paymentId'] : '';
            }, $started));
            self::assertCount(1, $ids, $message.': the same payment every time');
            foreach ($outcomes as $outcome) {
                if (PaymentInProgress::class === $outcome->kind) {
                    self::assertSame('Płatność za to zamówienie jest w toku. Spróbuj za chwilę.', $outcome->message);
                }
            }
        }
    }

    public function testW5TwoStartsWithDifferentKeysGiveOnePendingPaymentAndOneInProgress(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$orderId, $token] = $this->order();

            $outcomes = ConcurrentCommands::run($this->control, [
                new StartPayment($orderId, PaymentAccess::guest($token), 'demo-transfer', 'w5-a-'.Uuid::v7()->toRfc4122()),
                new StartPayment($orderId, PaymentAccess::guest($token), 'demo-card', 'w5-b-'.Uuid::v7()->toRfc4122()),
            ]);

            $message = 'W5 round '.$round.': '.self::messages($outcomes);
            self::assertSame([PaymentInProgress::class => 1, Outcome::SUCCESS => 1], self::kinds($outcomes), $message);
            self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM payment_payment WHERE order_id = ? AND status = 'pending'", $orderId), $message.': one pending payment');
            self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM payment_payment WHERE order_id = ?', $orderId), $message.': the loser wrote nothing');
            $loser = PaymentInProgress::class === $outcomes[0]->kind ? $outcomes[0] : $outcomes[1];
            self::assertSame('Płatność za to zamówienie jest w toku. Spróbuj za chwilę.', $loser->message, $message);
        }
    }

    /**
     * A guest order placed and committed by a participant of its own.
     *
     * @return array{string, string} the id and the access token
     */
    private function order(): array
    {
        [$placement, $variant, $product] = PaymentFixture::placement($this->control);
        $this->variants[] = $variant;
        $this->products[] = $product;
        $this->carts[] = $placement->cart->cartId ?? '';
        [$placed] = ConcurrentCommands::run($this->control, [$placement]);
        self::assertSame(Outcome::SUCCESS, $placed->kind, (string) $placed->message);
        $order = $placed->result['order'] ?? null;
        \assert(\is_array($order) && \is_string($order['orderId']) && \is_string($order['accessToken']));
        $this->orders[] = $order['orderId'];

        return [$order['orderId'], $order['accessToken']];
    }

    private function rowCount(string $sql, string $orderId): int
    {
        $count = $this->control->fetchOne($sql, [$orderId]);
        \assert(\is_int($count));

        return $count;
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
