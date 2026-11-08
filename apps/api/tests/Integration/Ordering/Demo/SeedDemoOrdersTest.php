<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Demo;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Tests\Integration\Cart\CartFixture;
use App\Tests\Integration\Ordering\OrderingFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * ordering:seed-demo on shop_test (rolled back after the test), after orders were placed through PlaceOrder: the orders
 * with their lines, history and notifications, and the queue of their ExpireUnpaidOrder messages (`scheduled`) go, the other
 * queues stay.
 */
final class SeedDemoOrdersTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testRemovesEveryOrderAndLineAndKeepsTheNumberSequence(): void
    {
        $number = 0;
        for ($i = 0; $i < 2; ++$i) {
            [$variant] = OrderingFixture::variant($this->connection, 1_000, 5);
            $cart = CartFixture::cart($this->connection, expiresAt: '+3 days', lines: [[$variant, 2]]);
            $number = (int) substr($this->place($cart)->order->number, 2);
        }
        self::assertGreaterThanOrEqual(2, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_order'));

        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('ordering:seed-demo'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(0, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_order'));
        self::assertSame(0, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_order_line'));
        self::assertSame(0, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_order_status_change'), 'the history of the statuses goes with the orders');
        $next = $this->connection->fetchOne("SELECT nextval('ordering_order_number_seq')");
        self::assertGreaterThan($number, $next, 'the numbers go on');
    }

    public function testRemovesTheNotificationsOfTheOrders(): void
    {
        $orderId = OrderingFixture::order($this->connection);
        $this->connection->executeStatement(
            "INSERT INTO ordering_order_notification (order_id, kind, claimed_until, sent_at) VALUES (?, 'confirmation', NULL, now()), (?, 'payment_received', now() + interval '5 minutes', NULL)",
            [$orderId, $orderId],
        );

        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('ordering:seed-demo'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(0, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_order_notification'));
        self::assertSame(0, OrderingFixture::count($this->connection, 'SELECT COUNT(*) FROM ordering_order'));
    }

    public function testRemovesTheScheduledQueueOnlyAndKeepsTheOutboxAndFailed(): void
    {
        foreach (['scheduled', 'scheduled', 'outbox', 'failed', 'events'] as $queue) {
            $this->connection->executeStatement(
                "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('{}', '{}', ?, now(), now() + interval '35 minutes')",
                [$queue],
            );
        }

        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('ordering:seed-demo'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(
            ['events' => 1, 'failed' => 1, 'outbox' => 1],
            $this->connection->fetchAllKeyValue('SELECT queue_name, COUNT(*) FROM messenger_messages GROUP BY queue_name ORDER BY queue_name'),
            'the ExpireUnpaidOrder messages of the removed orders are gone, everything else stays',
        );
    }

    private function place(string $cart): PlacedOrder
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $command = new PlaceOrder(CartReference::guest($cart), 'jan@example.com', 'Jan Kowalski', '600100200', 'Długa 1', '00-950', 'Warszawa', 2 * 1_230, 'seed-'.$cart);
        $result = $bus->dispatch($command)->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(PlacedOrder::class, $result);

        return $result;
    }
}
