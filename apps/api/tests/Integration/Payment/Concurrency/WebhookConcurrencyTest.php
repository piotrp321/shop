<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment\Concurrency;

use App\Kernel;
use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderPaid;
use App\Payment\Application\Command\HandleProviderWebhook;
use App\Payment\Application\Command\StartPayment;
use App\Payment\Application\OrderBusy;
use App\Payment\Application\PaymentAccess;
use App\Payment\Application\ProviderEvent;
use App\Payment\Domain\Amount;
use App\Payment\Domain\Currency;
use App\Payment\Domain\PaymentInProgress;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderEventId;
use App\Payment\Domain\ProviderPaymentId;
use App\PaymentSimulator\DecideTransaction;
use App\PaymentSimulator\DeliverSimulatedWebhook;
use App\PaymentSimulator\Provider;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Inventory\CatalogFixture;
use App\Tests\Integration\Inventory\Concurrency\ConcurrentCommands;
use App\Tests\Integration\Inventory\Concurrency\Outcome;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Integration\Payment\PaymentFixture;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Scenarios W1, W2, W3 and W6 of the Payment specification ("Współbieżność") on PostgreSQL: every participant is a
 * process of its own on its own connection (ConcurrentCommands), all start at once; each scenario runs 5 rounds on a
 * fresh order with a payment started at the simulator. The webhooks are HandleProviderWebhook on command.bus (the
 * signature is checked before, by the endpoint). Where the OrderPaid events are counted, the participants run the
 * `test_messaging` kernel, so the outbox is the Doctrine table. A delayed or asynchronous message (ExpireUnpaidOrder,
 * DeliverSimulatedWebhook) is handled at once, as a worker does, with the ReceivedStamp of its transport. The rows
 * commit, so they are deleted after the test.
 */
final class WebhookConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;
    private const string MESSAGING = 'test_messaging';

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
        // The cache of the messaging kernel is warm before ten participants boot it at once.
        $messaging = new Kernel(self::MESSAGING, false);
        $messaging->boot();
        $messaging->shutdown();
    }

    protected function tearDown(): void
    {
        foreach ($this->orders as $orderId) {
            $this->control->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'outbox' AND position(? IN body) > 0", [$orderId]);
        }
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

    public function testW1TheSameSuccessTenTimesChangesThePaymentAndTheOrderOnce(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$orderId, , $paymentId, $session] = $this->paymentStarted();
            $webhook = self::success($session, 'evt_w1_'.$round, new \DateTimeImmutable());

            $outcomes = ConcurrentCommands::run($this->control, array_fill(0, 10, $webhook), self::MESSAGING);

            $message = 'W1 round '.$round.': '.self::messages($outcomes);
            self::assertSame([Outcome::SUCCESS => 10], self::kinds($outcomes), $message.': every delivery answered 204');
            self::assertSame(['succeeded', null], $this->payment($paymentId), $message);
            self::assertSame(['paid', $paymentId, 2], $this->order($orderId), $message.': paid once (one version step)');
            self::assertSame(1, $this->orderPaidEvents($orderId), $message.': one OrderPaid');
            self::assertSame(1, $this->webhookEvents($paymentId), $message.': one row of the deduplication');
        }
    }

    public function testW2ASuccessAndARefusalOfOnePaymentEndSucceededAndPaidOnce(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$orderId, , $paymentId, $session] = $this->paymentStarted();

            $outcomes = ConcurrentCommands::run($this->control, [
                self::success($session, 'evt_w2_paid_'.$round, new \DateTimeImmutable()),
                new HandleProviderWebhook(ProviderCode::DemoTransfer, ProviderEvent::failed(ProviderEventId::fromString('evt_w2_rejected_'.$round), ProviderPaymentId::fromString($session), self::amount())),
            ], self::MESSAGING);

            $message = 'W2 round '.$round.': '.self::messages($outcomes);
            self::assertSame([Outcome::SUCCESS => 2], self::kinds($outcomes), $message);
            self::assertSame(['succeeded', null], $this->payment($paymentId), $message.': the money weighs more than the refusal');
            self::assertSame(['paid', $paymentId, 2], $this->order($orderId), $message);
            self::assertSame(1, $this->orderPaidEvents($orderId), $message);
            self::assertSame(2, $this->webhookEvents($paymentId), $message);
        }
    }

    public function testW3ASuccessAgainstTheExpiryEndsInExactlyOneStateAndACancelledOrderMarksTheRefundOnTheRetry(): void
    {
        $finals = [];
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$orderId, , $paymentId, $session] = $this->paymentStarted();
            // The deadline and the window of 5 minutes after it have passed; the payment was executed before the deadline.
            $this->control->executeStatement("UPDATE ordering_order SET payment_deadline = now() - interval '6 minutes' WHERE id = ?", [$orderId]);
            $deadline = $this->control->fetchOne("SELECT to_char(payment_deadline AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') FROM ordering_order WHERE id = ?", [$orderId]);
            \assert(\is_string($deadline));
            $webhook = self::success($session, 'evt_w3_'.$round, new \DateTimeImmutable($deadline)->modify('-1 minute'));
            $expiry = new Envelope(new ExpireUnpaidOrder($orderId), [new ReceivedStamp('scheduled')]);

            [$paying, $expiring] = ConcurrentCommands::run($this->control, [$webhook, $expiry]);

            $message = 'W3 round '.$round.': '.self::messages([$paying, $expiring]);
            self::assertContains($paying->kind, [Outcome::SUCCESS, OrderBusy::class], $message);
            self::assertContains($expiring->kind, [Outcome::SUCCESS, OrderModifiedConcurrently::class], $message);
            // The loser of the version is retried, as the simulator and the transport `scheduled` do.
            $retry = OrderBusy::class === $paying->kind ? $webhook : (Outcome::SUCCESS !== $expiring->kind ? $expiry : null);
            if (null !== $retry) {
                [$retried] = ConcurrentCommands::run($this->control, [$retry]);
                self::assertSame(Outcome::SUCCESS, $retried->kind, $message.'; retry: '.$retried->message);
            }

            [$status] = $this->order($orderId);
            $finals[] = $status;
            if ('paid' === $status) {
                self::assertSame(['succeeded', null], $this->payment($paymentId), $message);
                self::assertSame('active', $this->reservationStatus($orderId), $message.': a paid order keeps its reservation');
            } else {
                self::assertSame('cancelled', $status, $message);
                self::assertSame(['succeeded', 'order_cancelled'], $this->payment($paymentId), $message.': the payment of a cancelled order is kept for a refund');
                self::assertSame('released', $this->reservationStatus($orderId), $message);
            }
            self::assertSame(1, $this->webhookEvents($paymentId), $message);
        }
        self::assertCount(self::ROUNDS, $finals);
    }

    public function testW6ANewAttemptAgainstThePaymentAtTheSimulatorNeverGivesTwoSucceededPayments(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$orderId, $token, $first, $session] = $this->paymentStarted();

            [$decision, $attempt] = ConcurrentCommands::run($this->control, [
                new DecideTransaction($session, Provider::DemoTransfer, true),
                new StartPayment($orderId, PaymentAccess::guest($token), 'demo-card', 'w6-'.Uuid::v7()->toRfc4122()),
            ]);

            $message = 'W6 round '.$round.': '.self::messages([$decision, $attempt]);
            self::assertSame(Outcome::SUCCESS, $decision->kind, $message);
            if (true === ($decision->result['applied'] ?? null)) {
                self::assertSame(PaymentInProgress::class, $attempt->kind, $message.': the paid session cannot be cancelled');
                $this->deliver($session);
                self::assertSame(['succeeded', null], $this->payment($first), $message);
                self::assertSame(1, OrderingFixture::count($this->control, 'SELECT COUNT(*) FROM payment_payment WHERE order_id = ?', [$orderId]), $message.': no second payment');
                self::assertSame(['paid', $first, 2], $this->order($orderId), $message);
            } else {
                self::assertSame(Outcome::SUCCESS, $attempt->kind, $message);
                self::assertSame('cancelled', $this->control->fetchOne('SELECT status FROM payment_simulator_transaction WHERE id = ?', [$session]), $message.': the session was cancelled before the decision');
                self::assertSame(['cancelled', null], $this->payment($first), $message);
                $payment = $attempt->result['payment'] ?? null;
                \assert(\is_array($payment) && \is_string($payment['paymentId']));
                $second = $payment['paymentId'];
                $secondSession = $this->control->fetchOne('SELECT provider_payment_id FROM payment_payment WHERE id = ?', [$second]);
                \assert(\is_string($secondSession));
                [$paid] = ConcurrentCommands::run($this->control, [new DecideTransaction($secondSession, Provider::DemoCard, true)]);
                self::assertSame(Outcome::SUCCESS, $paid->kind, $message);
                $this->deliver($secondSession);
                self::assertSame(['paid', $second, 2], $this->order($orderId), $message);
            }
            self::assertSame(1, OrderingFixture::count($this->control, "SELECT COUNT(*) FROM payment_payment WHERE order_id = ? AND status = 'succeeded'", [$orderId]), $message.': never two succeeded payments');
        }
    }

    /**
     * A guest order placed and a payment started at demo-transfer, both committed by participants of their own.
     *
     * @return array{string, string, string, string} the order, its access token, the payment and its session
     */
    private function paymentStarted(): array
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

        [$started] = ConcurrentCommands::run($this->control, [new StartPayment($order['orderId'], PaymentAccess::guest($order['accessToken']), 'demo-transfer', 'start-'.$order['orderId'])]);
        self::assertSame(Outcome::SUCCESS, $started->kind, (string) $started->message);
        $payment = $started->result['payment'] ?? null;
        \assert(\is_array($payment) && \is_string($payment['paymentId']));
        $session = $this->control->fetchOne('SELECT provider_payment_id FROM payment_payment WHERE id = ?', [$payment['paymentId']]);
        \assert(\is_string($session));

        return [$order['orderId'], $order['accessToken'], $payment['paymentId'], $session];
    }

    /**
     * Delivers the webhook of a decided session of the simulator, as its worker does (HTTP to the webhook endpoint).
     */
    private function deliver(string $session): void
    {
        [$delivered] = ConcurrentCommands::run($this->control, [new Envelope(new DeliverSimulatedWebhook($session), [new ReceivedStamp('payment_simulator')])]);
        self::assertSame(Outcome::SUCCESS, $delivered->kind, (string) $delivered->message);
    }

    private static function success(string $session, string $eventId, \DateTimeImmutable $executedAt): HandleProviderWebhook
    {
        return new HandleProviderWebhook(ProviderCode::DemoTransfer, ProviderEvent::succeeded(ProviderEventId::fromString($eventId), ProviderPaymentId::fromString($session), self::amount(), $executedAt));
    }

    private static function amount(): Amount
    {
        return Amount::of(PaymentFixture::GROSS, Currency::PLN);
    }

    /**
     * @return array{string, ?string} the status and the refund reason
     */
    private function payment(string $paymentId): array
    {
        $row = $this->control->fetchNumeric('SELECT status, refund_required_reason FROM payment_payment WHERE id = ?', [$paymentId]);
        \assert(\is_array($row) && \is_string($row[0]) && (null === $row[1] || \is_string($row[1])));

        return [$row[0], $row[1]];
    }

    /**
     * @return array{string, ?string, int} the status, the payment that paid it and the version
     */
    private function order(string $orderId): array
    {
        $row = $this->control->fetchNumeric('SELECT status, payment_id, version FROM ordering_order WHERE id = ?', [$orderId]);
        \assert(\is_array($row) && \is_string($row[0]) && (null === $row[1] || \is_string($row[1])) && \is_int($row[2]));

        return [$row[0], $row[1], $row[2]];
    }

    private function reservationStatus(string $orderId): mixed
    {
        return $this->control->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]);
    }

    private function webhookEvents(string $paymentId): int
    {
        return OrderingFixture::count($this->control, 'SELECT COUNT(*) FROM payment_webhook_event WHERE payment_id = ?', [$paymentId]);
    }

    private function orderPaidEvents(string $orderId): int
    {
        return OrderingFixture::count(
            $this->control,
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox' AND position(? IN body) > 0 AND position(? IN body) > 0",
            [addslashes(OrderPaid::class), $orderId],
        );
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
        return implode('; ', array_map(static fn (Outcome $o): string => $o->kind.(null === $o->message ? '' : ' '.$o->message), $outcomes));
    }
}
