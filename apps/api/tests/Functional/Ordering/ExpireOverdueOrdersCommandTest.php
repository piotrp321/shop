<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\ExpireUnpaidOrder;
use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\OverdueOrders;
use App\Ordering\Domain\OrderCancelled;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalOverdueOrderIds;
use App\Ordering\Infrastructure\SystemClock;
use App\Ordering\UI\Console\ExpireOverdueOrdersCommand;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Tests\Factory\DiscountCodeFactory;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use App\Tests\Unit\Ordering\SpyLogger;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

/**
 * ordering:expire-overdue (spec: docs/features/async-reliability/spec.md, W5, criterion 5), the safety net of the delayed
 * ExpireUnpaidOrder, on shop_test with the Doctrine transports of the `test_messaging` kernel (rolled back after each
 * test): an order whose message went to `failed` is cancelled by the command with everything the message does, and no
 * message goes to a transport. The orders are placed now and moved into the past by SQL; the command uses the system
 * time.
 */
final class ExpireOverdueOrdersCommandTest extends MessagingKernelTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->connection();
    }

    public function testAnOverdueOrderWhoseMessageIsInFailedIsCancelledWithoutAnyMessageOnATransport(): void
    {
        $code = DiscountCodeFactory::createOne(['code' => 'EXPIRY-NET', 'value' => 10]);
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        new DoctrineDiscountCodeRepository($entityManager)->save($code);
        [$shoes, $orderId] = $this->placeOrder('EXPIRY-NET');
        // The delayed message failed five times and lies in `failed`.
        $this->db->executeStatement("UPDATE messenger_messages SET queue_name = 'failed' WHERE queue_name = 'scheduled'");
        $this->moveDeadline($orderId, 16);
        $outboxBefore = $this->rows('outbox');

        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('Expired 1 overdue order.', $tester->getDisplay());
        self::assertSame(['cancelled', 'payment_timeout'], $this->db->fetchNumeric('SELECT status, cancellation_reason FROM ordering_order WHERE id = ?', [$orderId]));
        self::assertSame([5, 0], OrderingFixture::stock($this->db, $shoes), 'the reservation is released');
        self::assertSame('released', $this->db->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$orderId]));
        self::assertTrue($this->db->fetchOne('SELECT released_at IS NOT NULL FROM pricing_discount_code_usage WHERE order_id = ?', [$orderId]), 'the usage of the code is released');
        self::assertSame(1, $this->cancelledEvents($orderId));
        self::assertSame($outboxBefore + 1, $this->rows('outbox'), 'only OrderCancelled');
        self::assertSame(0, $this->rows('scheduled'));
        self::assertSame(1, $this->rows('failed'), 'the message in `failed` stays (its alert goes on)');
        self::assertSame(0, $this->db->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE position('ExpireOverdueOrder' IN body) > 0"));
    }

    public function testOrdersBeforeTheGracePaidAndCancelledStayAsTheyAre(): void
    {
        [, $withinGrace] = $this->placeOrder();
        // The deadline 14 minutes ago: past the window of the webhooks (5 min), not past the grace (10 min more).
        $this->moveDeadline($withinGrace, 14);
        [, $paid] = $this->placeOrder();
        $this->dispatch(new MarkOrderPaid($paid, Uuid::v7()->toRfc4122(), 2 * 12_300, 'PLN', new \DateTimeImmutable()));
        $this->moveDeadline($paid, 60);
        [, $cancelled] = $this->placeOrder();
        $this->moveDeadline($cancelled, 60);
        $this->dispatch(new Envelope(new ExpireUnpaidOrder($cancelled), [new ReceivedStamp('scheduled')]));
        $before = $this->orders([$withinGrace, $paid, $cancelled]);

        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('No overdue orders.', $tester->getDisplay());
        self::assertSame($before, $this->orders([$withinGrace, $paid, $cancelled]));
        self::assertSame('new', $before[0]['status']);
        self::assertSame('paid', $before[1]['status']);
        self::assertSame('cancelled', $before[2]['status']);
    }

    public function testASecondRunChangesNothing(): void
    {
        [$shoes, $orderId] = $this->placeOrder();
        $this->moveDeadline($orderId, 30);
        $tester = $this->tester();
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        $after = [$this->orders([$orderId]), OrderingFixture::stock($this->db, $shoes), $this->rows('outbox'), $this->rows('scheduled')];

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        self::assertStringContainsString('No overdue orders.', $tester->getDisplay());
        self::assertSame($after, [$this->orders([$orderId]), OrderingFixture::stock($this->db, $shoes), $this->rows('outbox'), $this->rows('scheduled')]);
        self::assertSame(1, $this->cancelledEvents($orderId));
    }

    public function testNoCandidatesIsASuccess(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('No overdue orders.', $tester->getDisplay());
    }

    public function testEveryBatchIsWalked(): void
    {
        $orders = [];
        for ($i = 1; $i <= 3; ++$i) {
            [, $orders[]] = $this->placeOrder();
            $this->moveDeadline($orders[$i - 1], 20 + $i);
        }
        $tester = $this->tester();

        self::assertSame(0, $tester->execute(['--batch-size' => '1']), $tester->getDisplay());

        self::assertStringContainsString('Expired 3 overdue orders.', $tester->getDisplay());
        self::assertSame(['cancelled', 'cancelled', 'cancelled'], array_column($this->orders($orders), 'status'));
    }

    public function testABatchSizeThatIsNoPositiveIntegerIsRefusedWithoutChanges(): void
    {
        [, $orderId] = $this->placeOrder();
        $this->moveDeadline($orderId, 30);
        $tester = $this->tester();

        foreach (['0', '-5', 'many', '1.5', ''] as $size) {
            self::assertSame(1, $tester->execute(['--batch-size' => $size]), $size);
            self::assertStringContainsString('The batch size must be a positive integer.', $tester->getDisplay());
        }
        self::assertSame('new', $this->db->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]));
    }

    /**
     * The order in the middle of three has no reservation Inventory knows: its expiry fails and is logged with its id;
     * the other two are cancelled and the exit code is 2.
     */
    public function testAFailureOfOneOrderIsLoggedTheOthersAreExpiredAndTheExitCodeIsTwo(): void
    {
        $orders = [];
        for ($i = 1; $i <= 3; ++$i) {
            [, $orders[]] = $this->placeOrder();
            $this->moveDeadline($orders[$i - 1], 40 - $i);
        }
        $this->db->executeStatement('DELETE FROM inventory_reservation_line WHERE reservation_key = ?', [$orders[1]]);
        $this->db->executeStatement('DELETE FROM inventory_reservation WHERE reservation_key = ?', [$orders[1]]);
        $logger = new SpyLogger();
        $tester = new CommandTester($this->command($logger));

        self::assertSame(2, $tester->execute([]), $tester->getDisplay());

        self::assertStringContainsString('Expired 2 overdue orders; 1 could not be expired', $tester->getDisplay());
        self::assertSame(['cancelled', 'new', 'cancelled'], array_column($this->orders($orders), 'status'));
        $errors = array_values(array_filter($logger->records, static fn (array $record): bool => 'error' === $record[0]));
        self::assertCount(1, $errors);
        self::assertSame($orders[1], $errors[0][2]['orderId'] ?? null);
        self::assertInstanceOf(\Throwable::class, $errors[0][2]['exception'] ?? null);
    }

    /**
     * The write of the first order fails in the database, which closes the entity manager; the command replaces it, so
     * the next order is expired.
     */
    public function testAFailedWriteOfOneOrderDoesNotStopTheNext(): void
    {
        [, $first] = $this->placeOrder();
        $this->moveDeadline($first, 40);
        [, $second] = $this->placeOrder();
        $this->moveDeadline($second, 30);
        $this->db->executeStatement("CREATE FUNCTION ordering_test_fail_expiry() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'forced failure of an expiry'; END \$\$");
        $this->db->executeStatement(\sprintf("CREATE TRIGGER ordering_test_fail_expiry BEFORE UPDATE ON ordering_order FOR EACH ROW WHEN (OLD.id = '%s'::uuid) EXECUTE FUNCTION ordering_test_fail_expiry()", $first));
        $logger = new SpyLogger();
        $tester = new CommandTester($this->command($logger));

        self::assertSame(2, $tester->execute([]), $tester->getDisplay());

        self::assertSame(['new', 'cancelled'], array_column($this->orders([$first, $second]), 'status'));
        self::assertStringContainsString('Expired 1 overdue order; 1 could not be expired', $tester->getDisplay());
        self::assertSame($first, $logger->records[0][2]['orderId'] ?? null);
    }

    public function testAnUnavailableDatabaseStopsWithTwo(): void
    {
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->db->getParams();
        // Nothing listens there: every statement fails to connect, as when PostgreSQL is down.
        $unreachable = DriverManager::getConnection(['host' => '127.0.0.1', 'port' => 1] + $params, new DbalConfiguration());
        $container = self::getContainer();
        $bus = $container->get('command.bus');
        $doctrine = $container->get('doctrine');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $application = new ConsoleApplication();
        $application->addCommand(new ExpireOverdueOrdersCommand(new OverdueOrders(new DbalOverdueOrderIds($unreachable), new SystemClock(), 600), $bus, $doctrine, new SpyLogger()));
        $application->setAutoExit(false);
        $application->setCatchExceptions(true);
        $tester = new ApplicationTester($application);

        self::assertSame(2, $tester->run(['command' => 'ordering:expire-overdue']), $tester->getDisplay());
        self::assertStringContainsString('The expiry stopped:', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        self::assertNotNull(self::$kernel);

        return new CommandTester((new Application(self::$kernel))->find('ordering:expire-overdue'));
    }

    private function command(SpyLogger $logger): ExpireOverdueOrdersCommand
    {
        $container = self::getContainer();
        $bus = $container->get('command.bus');
        $doctrine = $container->get('doctrine');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return new ExpireOverdueOrdersCommand(new OverdueOrders(new DbalOverdueOrderIds($this->db), new SystemClock(), 600), $bus, $doctrine, $logger);
    }

    /**
     * @return array{string, string} the variant (5 units, 2 reserved by the order) and the id of the order, new
     */
    private function placeOrder(?string $discountCode = null): array
    {
        [$shoes] = OrderingFixture::variant($this->db, 10_000, 5);
        $cart = CartFixture::cart($this->db, expiresAt: '+3 days', lines: [[$shoes, 2]]);
        $total = null === $discountCode ? 2 * 12_300 : 2 * 12_300 - 2_460;
        $placed = $this->dispatch(new PlaceOrder(CartReference::guest($cart), 'jan@example.com', 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', OrderingFixture::freeDeliveryMethod($this->db), $total, 'key-'.$cart, $discountCode));
        self::assertInstanceOf(PlacedOrder::class, $placed);

        return [$shoes, $placed->order->orderId];
    }

    /**
     * Moves the order into the past: its deadline (placed_at + 30 minutes) is the given number of minutes ago.
     */
    private function moveDeadline(string $orderId, int $minutesAgo): void
    {
        $this->db->executeStatement(
            'UPDATE ordering_order SET placed_at = now() - make_interval(mins => ?), payment_deadline = now() - make_interval(mins => ?) WHERE id = ?',
            [$minutesAgo + 30, $minutesAgo, $orderId],
        );
    }

    private function dispatch(object $message): mixed
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }

    /**
     * @param list<string> $orderIds
     *
     * @return list<array<string, mixed>> status, version and cancellation of each order, in the order given
     */
    private function orders(array $orderIds): array
    {
        return array_map(
            fn (string $id): array => $this->db->fetchAssociative('SELECT status, version, cancelled_at, cancellation_reason FROM ordering_order WHERE id = ?', [$id]) ?: [],
            $orderIds,
        );
    }

    private function cancelledEvents(string $orderId): int
    {
        return OrderingFixture::count(
            $this->db,
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox' AND position(? IN body) > 0 AND position(? IN body) > 0",
            [addslashes(OrderCancelled::class), $orderId],
        );
    }
}
