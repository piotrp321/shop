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
 * Scenarios W4, W5 and W7 of the Payment specification ("Współbieżność") and the starts during a start at the
 * operator (ABUSE-LIMITS L7) on PostgreSQL: every participant is a process of its own dispatching StartPayment on
 * command.bus (ConcurrentCommands), all start at once; each calls the simulator through KernelForwardingHttpClient on
 * its own connection. In W7 the webhook of the earlier payment is a forked process of its own that holds its
 * transaction open until the start waits for its lock. The paused operator is a forked process that locks the table
 * of the simulator's sessions, so a start opening its session waits there in its transaction, as for an operator that
 * does not answer; it lets the start go when the test resumes it or after the time limit of a call to the operator.
 * Each scenario runs 5 rounds on a fresh order. The rows commit, so they are deleted after the test.
 */
final class StartPaymentConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;

    private const float WEBHOOK_TIMEOUT_SECONDS = 30.0;

    /** The time limit of a call to the operator (ProviderHttp): a paused operator answers after it at the latest. */
    private const float OPERATOR_TIMEOUT_SECONDS = 5.0;

    /** The starts that run while another start of the order is at the operator. */
    private const int CONCURRENT_STARTS = 10;

    /** How long those starts may take together, the boot of their kernels included: well below OPERATOR_TIMEOUT. */
    private const float AT_ONCE_SECONDS = 2.5;

    private const float BACKGROUND_TIMEOUT_SECONDS = 60.0;

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
     * L7: a start of the order holds the lock of its starts while the operator does not answer; starts with other
     * keys, with both providers, and with the key of the start in flight end at once with 409 (they do not wait on
     * the unique indexes for the first one to end), and write nothing. Once the first start ends, the next one cancels
     * its payment at the operator and starts its own, as before.
     */
    public function testStartsDuringAStartAtThePausedOperatorAreInProgressAtOnce(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$orderId, $token] = $this->order();
            $access = PaymentAccess::guest($token);
            $firstKey = 'l7-first-'.Uuid::v7()->toRfc4122();
            $operator = $this->pausedOperator();
            $first = null;
            try {
                $first = $this->startInBackground(new StartPayment($orderId, $access, 'demo-transfer', $firstKey));
                $this->waitUntilStartsWaitAtTheOperator(1, $first);

                $commands = [new StartPayment($orderId, $access, 'demo-transfer', $firstKey)];
                for ($index = 1; $index < self::CONCURRENT_STARTS; ++$index) {
                    $commands[] = new StartPayment($orderId, $access, 0 === $index % 2 ? 'demo-transfer' : 'demo-card', 'l7-'.$index.'-'.Uuid::v7()->toRfc4122());
                }
                $started = microtime(true);
                $outcomes = ConcurrentCommands::run($this->control, $commands);
                $elapsed = microtime(true) - $started;
                $firstStillAtTheOperator = 1 === $this->startsWaitingAtTheOperator() && !self::finishedInBackground($first);
            } finally {
                $resumed = $this->resumedOperator($operator);
                $firstOutcome = null === $first ? null : self::outcomeInBackground($first);
            }

            $message = 'L7 round '.$round.': '.self::messages($outcomes);
            self::assertLessThan(self::AT_ONCE_SECONDS, $elapsed, \sprintf('%s: %d starts took %.2f s, the operator answers after %.1f s at the latest', $message, self::CONCURRENT_STARTS, $elapsed, self::OPERATOR_TIMEOUT_SECONDS));
            self::assertTrue($firstStillAtTheOperator, $message.': the starts ended while the first one was at the operator');
            self::assertSame([PaymentInProgress::class => self::CONCURRENT_STARTS], self::kinds($outcomes), $message);
            foreach ($outcomes as $outcome) {
                self::assertSame('Płatność za to zamówienie jest w toku. Spróbuj za chwilę.', $outcome->message, $message);
            }
            self::assertSame('resumed by the test', $resumed, $message);
            self::assertNotNull($firstOutcome);
            self::assertSame(Outcome::SUCCESS, $firstOutcome->kind, $message.': the first start: '.$firstOutcome->message);
            self::assertFalse($firstOutcome->result['replayed'] ?? null, $message);
            self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM payment_payment WHERE order_id = ?', $orderId), $message.': the refused starts wrote nothing');
            self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM payment_payment WHERE order_id = ? AND status = 'pending' AND provider_payment_id IS NOT NULL AND idempotency_key = '".$firstKey."'", $orderId), $message.': the payment of the first start, pending with its session');

            [$next] = ConcurrentCommands::run($this->control, [new StartPayment($orderId, $access, 'demo-card', 'l7-next-'.Uuid::v7()->toRfc4122())]);
            self::assertSame(Outcome::SUCCESS, $next->kind, $message.': the next start: '.$next->message);
            self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM payment_payment WHERE order_id = ? AND status = 'cancelled' AND idempotency_key = '".$firstKey."'", $orderId), $message.': the first payment cancelled at its operator');
            self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM payment_payment WHERE order_id = ? AND status = 'pending' AND provider = 'demo-card'", $orderId), $message.': the next payment pending');
        }
    }

    /**
     * L7: the start of another order is not held by the lock of a start at the paused operator: it reaches the
     * operator too, and both end with their payments.
     */
    public function testStartsOfDifferentOrdersDoNotHoldEachOther(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$orderId, $token] = $this->order();
            [$otherId, $otherToken] = $this->order();
            $operator = $this->pausedOperator();
            $starts = [];
            $outcomes = [];
            try {
                $starts[] = $this->startInBackground(new StartPayment($orderId, PaymentAccess::guest($token), 'demo-transfer', 'l7-a-'.Uuid::v7()->toRfc4122()));
                $this->waitUntilStartsWaitAtTheOperator(1, $starts[0]);
                $starts[] = $this->startInBackground(new StartPayment($otherId, PaymentAccess::guest($otherToken), 'demo-transfer', 'l7-b-'.Uuid::v7()->toRfc4122()));
                $this->waitUntilStartsWaitAtTheOperator(2, $starts[1]);
            } finally {
                $resumed = $this->resumedOperator($operator);
                $outcomes = array_map(self::outcomeInBackground(...), $starts);
            }

            $message = 'L7 round '.$round.': '.self::messages($outcomes);
            self::assertSame('resumed by the test', $resumed, $message);
            self::assertSame([Outcome::SUCCESS => 2], self::kinds($outcomes), $message);
            foreach ([$orderId, $otherId] as $id) {
                self::assertSame(1, $this->rowCount("SELECT COUNT(*) FROM payment_payment WHERE order_id = ? AND status = 'pending' AND provider_payment_id IS NOT NULL", $id), $message.': a pending payment of each order');
            }
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
     * Forks the paused operator: on a connection of its own it locks the table of the simulator's sessions against
     * writes (reads go on), so a start opening its session waits in its transaction. It lets go when the test resumes
     * it, or after the time limit of a call to the operator. Returns once the table is locked.
     *
     * @return array{int, string} the pid of the child and the directory of its files
     */
    private function pausedOperator(): array
    {
        $directory = self::directory('payment-operator');
        gc_collect_cycles(); // see ConcurrentCommands::run
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new \RuntimeException('Cannot fork the operator.');
        }
        if (0 === $pid) {
            $this->pauseTheOperator($directory);
        }

        $deadline = microtime(true) + self::WEBHOOK_TIMEOUT_SECONDS;
        while (!is_file($directory.'/locked') && !is_file($directory.'/outcome')) {
            if (microtime(true) > $deadline) {
                posix_kill($pid, \SIGKILL);
                pcntl_waitpid($pid, $status);
                throw new \RuntimeException('The operator did not pause in time.');
            }
            usleep(5000);
        }

        return [$pid, $directory];
    }

    /**
     * Never returns: the process kills itself.
     */
    private function pauseTheOperator(string $directory): void
    {
        ini_set('display_errors', '0');
        register_shutdown_function(static function (): void {
            posix_kill(posix_getpid(), \SIGKILL);
        });

        try {
            $connection = DriverManager::getConnection($this->params, new DbalConfiguration());
            $connection->executeStatement("SET lock_timeout = '10s'");
            $connection->beginTransaction();
            $connection->executeStatement('LOCK TABLE payment_simulator_transaction IN EXCLUSIVE MODE');
            file_put_contents($directory.'/locked', '');

            $deadline = microtime(true) + self::OPERATOR_TIMEOUT_SECONDS;
            while (!is_file($directory.'/resume') && microtime(true) < $deadline) {
                usleep(5000);
            }
            $outcome = is_file($directory.'/resume') ? 'resumed by the test' : 'answered after the time limit of the operator';
            $connection->commit();
            $connection->close();
        } catch (\Throwable $failure) {
            $outcome = $failure::class.': '.$failure->getMessage();
        }

        file_put_contents($directory.'/outcome', $outcome);
        posix_kill(posix_getpid(), \SIGKILL);
    }

    /**
     * @param array{int, string} $operator
     *
     * @return string what the operator reported
     */
    private function resumedOperator(array $operator): string
    {
        [$pid, $directory] = $operator;
        touch($directory.'/resume');
        pcntl_waitpid($pid, $status);
        $outcome = is_file($directory.'/outcome') ? (string) file_get_contents($directory.'/outcome') : 'no outcome';
        self::removeDirectory($directory);

        return $outcome;
    }

    /**
     * Forks a process that runs the start as the one participant of ConcurrentCommands, on a control connection of
     * its own, and leaves its outcome in a file; the test goes on meanwhile.
     *
     * @return array{int, string} the pid of the child and the directory of its files
     */
    private function startInBackground(StartPayment $command): array
    {
        $directory = self::directory('payment-start');
        gc_collect_cycles(); // see ConcurrentCommands::run
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new \RuntimeException('Cannot fork the start.');
        }
        if (0 === $pid) {
            $this->startAndReport($command, $directory);
        }

        return [$pid, $directory];
    }

    /**
     * Never returns: the process kills itself.
     */
    private function startAndReport(StartPayment $command, string $directory): void
    {
        ini_set('display_errors', '0');
        register_shutdown_function(static function (): void {
            posix_kill(posix_getpid(), \SIGKILL);
        });

        try {
            $control = DriverManager::getConnection($this->params, new DbalConfiguration());
            [$outcome] = ConcurrentCommands::run($control, [$command]);
            $control->close();
        } catch (\Throwable $failure) {
            $outcome = Outcome::failure($failure);
        }

        file_put_contents($directory.'/outcome', $outcome->toJson());
        posix_kill(posix_getpid(), \SIGKILL);
    }

    /**
     * @param array{int, string} $start
     */
    private static function finishedInBackground(array $start): bool
    {
        return is_file($start[1].'/outcome');
    }

    /**
     * Waits for the start in the background (or kills it after the time limit) and gives its outcome.
     *
     * @param array{int, string} $start
     */
    private static function outcomeInBackground(array $start): Outcome
    {
        [$pid, $directory] = $start;
        $deadline = microtime(true) + self::BACKGROUND_TIMEOUT_SECONDS;
        while (0 === pcntl_waitpid($pid, $status, \WNOHANG)) {
            if (microtime(true) > $deadline) {
                posix_kill($pid, \SIGKILL);
                pcntl_waitpid($pid, $status);
                break;
            }
            usleep(5000);
        }
        $content = is_file($directory.'/outcome') ? (string) file_get_contents($directory.'/outcome') : '';
        self::removeDirectory($directory);

        return '' === $content ? Outcome::failure(new \RuntimeException('The start in the background left no outcome.')) : Outcome::fromJson($content);
    }

    /**
     * Waits until the given number of starts wait for the paused operator (for the lock of its table).
     *
     * @param array{int, string} $latest the start expected to arrive last: if it ends instead, waiting stops
     */
    private function waitUntilStartsWaitAtTheOperator(int $count, array $latest): void
    {
        $deadline = microtime(true) + self::WEBHOOK_TIMEOUT_SECONDS;
        while ($this->startsWaitingAtTheOperator() < $count) {
            if (self::finishedInBackground($latest)) {
                throw new \RuntimeException('The start ended instead of waiting for the operator: '.(string) file_get_contents($latest[1].'/outcome'));
            }
            if (microtime(true) > $deadline) {
                throw new \RuntimeException(\sprintf('%d starts did not reach the operator in time.', $count));
            }
            usleep(5000);
        }
    }

    private function startsWaitingAtTheOperator(): int
    {
        $count = $this->control->fetchOne("SELECT COUNT(*) FROM pg_locks WHERE locktype = 'relation' AND relation = CAST('payment_simulator_transaction' AS regclass) AND NOT granted");
        \assert(\is_int($count));

        return $count;
    }

    private static function directory(string $prefix): string
    {
        $directory = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(8));
        if (!mkdir($directory, 0o700)) {
            throw new \RuntimeException('Cannot create the directory '.$directory.'.');
        }

        return $directory;
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
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
