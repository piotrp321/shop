<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment\Concurrency;

use App\Payment\Application\Command\StartPayment;
use App\Payment\Application\PaymentAccess;
use App\Payment\Domain\OrderAlreadyPaid;
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
 * Scenarios W4, W5 and W7 of the Payment specification ("Współbieżność") on PostgreSQL: every participant is a
 * process of its own dispatching StartPayment on command.bus (ConcurrentCommands), all start at once; each calls the
 * simulator through KernelForwardingHttpClient on its own connection. In W7 the webhook of the earlier payment is a
 * forked process of its own that holds its transaction open until the start waits for its lock. Each scenario runs
 * 5 rounds on a fresh order. The rows commit, so they are deleted after the test.
 */
final class StartPaymentConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;

    private const float WEBHOOK_TIMEOUT_SECONDS = 30.0;

    /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} */
    private array $params;
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
        $this->params = $params;
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
     * The webhook of the earlier payment has written the payment succeeded and the order paid and holds the lock of
     * the payment's row; StartPayment with a new key reads the order still new, then waits for that lock. The webhook
     * commits once the start waits: the start sees the order paid and writes nothing.
     */
    public function testW7AStartWaitingForTheWebhookOfTheEarlierPaymentEndsInOrderAlreadyPaid(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$orderId, $token, $paymentId] = $this->paymentStarted();
            $webhook = $this->webhookHoldingTheLock($orderId, $paymentId);

            [$attempt] = ConcurrentCommands::run($this->control, [
                new StartPayment($orderId, PaymentAccess::guest($token), 'demo-card', 'w7-'.Uuid::v7()->toRfc4122()),
            ]);
            $webhookOutcome = $this->webhookCommitted($webhook);

            $message = 'W7 round '.$round.': '.$attempt->kind.(null === $attempt->message ? '' : ' '.$attempt->message);
            self::assertSame('committed after the start waited', $webhookOutcome, $message);
            self::assertSame(OrderAlreadyPaid::class, $attempt->kind, $message);
            self::assertSame('Zamówienie jest już opłacone.', $attempt->message, $message);
            self::assertSame(0, $this->rowCount("SELECT COUNT(*) FROM payment_payment WHERE order_id = ? AND status = 'pending'", $orderId), $message.': no new pending payment');
            self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM payment_payment WHERE order_id = ?', $orderId), $message.': the start wrote nothing');
            self::assertSame('paid', $this->control->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]), $message);
        }
    }

    /**
     * A guest order placed and a payment started at demo-transfer, both committed by participants of their own.
     *
     * @return array{string, string, string} the order, its access token and the payment
     */
    private function paymentStarted(): array
    {
        [$orderId, $token] = $this->order();
        [$started] = ConcurrentCommands::run($this->control, [new StartPayment($orderId, PaymentAccess::guest($token), 'demo-transfer', 'start-'.$orderId)]);
        self::assertSame(Outcome::SUCCESS, $started->kind, (string) $started->message);
        $payment = $started->result['payment'] ?? null;
        \assert(\is_array($payment) && \is_string($payment['paymentId']));

        return [$orderId, $token, $payment['paymentId']];
    }

    /**
     * Forks the webhook of the payment: on a connection of its own it locks the payment's row, writes the payment
     * succeeded and the order paid (as HandleProviderWebhook with MarkOrderPaid does), and keeps the transaction open
     * until another backend waits for its locks (or the timeout), then commits. Returns once the writes are done.
     *
     * Like a participant of ConcurrentCommands, the child kills itself (SIGKILL) so that it never runs the destructors
     * it inherited from the parent.
     *
     * @return array{int, string} the pid of the child and the directory of its files
     */
    private function webhookHoldingTheLock(string $orderId, string $paymentId): array
    {
        $directory = sys_get_temp_dir().'/payment-w7-'.bin2hex(random_bytes(8));
        if (!mkdir($directory, 0o700)) {
            throw new \RuntimeException('Cannot create the directory of the webhook.');
        }
        gc_collect_cycles(); // see ConcurrentCommands::run
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new \RuntimeException('Cannot fork the webhook.');
        }
        if (0 === $pid) {
            $this->holdTheLock($orderId, $paymentId, $directory);
        }

        $deadline = microtime(true) + self::WEBHOOK_TIMEOUT_SECONDS;
        while (!is_file($directory.'/locked') && !is_file($directory.'/outcome')) {
            if (microtime(true) > $deadline) {
                posix_kill($pid, \SIGKILL);
                pcntl_waitpid($pid, $status);
                throw new \RuntimeException('The webhook did not lock the payment in time.');
            }
            usleep(5000);
        }

        return [$pid, $directory];
    }

    /**
     * Never returns: the process kills itself.
     */
    private function holdTheLock(string $orderId, string $paymentId, string $directory): void
    {
        ini_set('display_errors', '0');
        register_shutdown_function(static function (): void {
            posix_kill(posix_getpid(), \SIGKILL);
        });

        try {
            $connection = DriverManager::getConnection($this->params, new DbalConfiguration());
            $connection->executeStatement("SET lock_timeout = '10s'");
            $connection->beginTransaction();
            $connection->fetchOne('SELECT id FROM payment_payment WHERE id = ? FOR UPDATE', [$paymentId]);
            $connection->executeStatement("UPDATE payment_payment SET status = 'succeeded', executed_at = now(), completed_at = now() WHERE id = ?", [$paymentId]);
            $connection->executeStatement("UPDATE ordering_order SET status = 'paid', paid_at = now(), payment_id = ?, version = version + 1 WHERE id = ?", [$paymentId, $orderId]);
            file_put_contents($directory.'/locked', '');

            $backend = $connection->fetchOne('SELECT pg_backend_pid()');
            $deadline = microtime(true) + self::WEBHOOK_TIMEOUT_SECONDS;
            $waited = false;
            // pg_locks is read live on every query (pg_stat_activity would be a snapshot of the transaction).
            while (!$waited && microtime(true) < $deadline) {
                $waited = 0 < $connection->fetchOne('SELECT COUNT(*) FROM pg_locks WHERE NOT granted AND CAST(? AS int) = ANY(pg_blocking_pids(pid))', [$backend]);
                if (!$waited) {
                    usleep(5000);
                }
            }
            $connection->commit();
            $connection->close();
            $outcome = $waited ? 'committed after the start waited' : 'committed without anybody waiting';
        } catch (\Throwable $failure) {
            $outcome = $failure::class.': '.$failure->getMessage();
        }

        file_put_contents($directory.'/outcome', $outcome);
        posix_kill(posix_getpid(), \SIGKILL);
    }

    /**
     * @param array{int, string} $webhook
     *
     * @return string what the webhook reported
     */
    private function webhookCommitted(array $webhook): string
    {
        [$pid, $directory] = $webhook;
        pcntl_waitpid($pid, $status);
        $outcome = is_file($directory.'/outcome') ? (string) file_get_contents($directory.'/outcome') : 'no outcome';
        foreach (['locked', 'outcome'] as $file) {
            if (is_file($directory.'/'.$file)) {
                unlink($directory.'/'.$file);
            }
        }
        rmdir($directory);

        return $outcome;
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
