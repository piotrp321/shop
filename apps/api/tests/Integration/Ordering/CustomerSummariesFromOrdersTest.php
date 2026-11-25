<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Command\MarkOrderPaid;
use App\Ordering\Application\Command\MarkOrderPaidResult;
use App\Ordering\Application\Command\PlacedOrder;
use App\Ordering\Application\Command\PlaceOrder;
use App\Tests\Integration\Cart\CartFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;

/**
 * PlaceOrder and MarkOrderPaid on command.bus with the real adapters, in the transaction DAMA rolls back after each
 * test: a new order of a customer adds to its summary in the transaction of the placement, a replay after
 * Idempotency-Key adds nothing, only the payment that pays the order counts it as paid, and a guest is no customer.
 */
final class CustomerSummariesFromOrdersTest extends KernelTestCase
{
    private const string CUSTOMER = 'kc-summary-orders';
    /** 10 000 net + 23 % VAT. */
    private const int GROSS = 12_300;

    private Connection $connection;
    private MessageBusInterface $bus;
    /** @var list<array{string, int}> the lines of the cart of the customer of placeAsCustomer() */
    private array $customerLines = [];

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->connection = $connection;
        $this->bus = $bus;
        $this->connection->executeStatement('DELETE FROM ordering_customer');
    }

    public function testAnOrderOfACustomerIsAddedOnceAndAReplayAddsNothing(): void
    {
        $placed = $this->placeAsCustomer('key-summary-1');
        $replayed = $this->place(CartReference::customer(self::CUSTOMER), 'key-summary-1', $this->customerLines);

        self::assertTrue($replayed->replayed);
        self::assertSame([[self::CUSTOMER, 'anna@example.com', 'Anna Nowak', '+48600100200', 1, 0, 0, $placed->order->orderId]], $this->summaries());
    }

    public function testOnlyThePaymentThatPaysTheOrderCountsItAsPaid(): void
    {
        $placed = $this->placeAsCustomer('key-summary-2');
        $payment = Uuid::v7()->toRfc4122();

        self::assertSame(MarkOrderPaidResult::Paid, $this->pay($placed->order->orderId, $payment));
        self::assertSame(MarkOrderPaidResult::AlreadyPaid, $this->pay($placed->order->orderId, $payment));
        self::assertSame(MarkOrderPaidResult::PaidByAnotherPayment, $this->pay($placed->order->orderId, Uuid::v7()->toRfc4122()));

        self::assertSame([[self::CUSTOMER, 'anna@example.com', 'Anna Nowak', '+48600100200', 1, 1, self::GROSS, $placed->order->orderId]], $this->summaries());
    }

    public function testAnOrderOfAGuestAndItsPaymentMakeNoCustomer(): void
    {
        [$variant] = OrderingFixture::variant($this->connection, 10_000, 5);
        $cart = CartFixture::cart($this->connection, lines: [[$variant, 1]]);
        $placed = $this->place(CartReference::guest($cart), 'key-summary-3', [[$variant, 1]]);
        $this->pay($placed->order->orderId, Uuid::v7()->toRfc4122());

        self::assertSame([], $this->summaries());
    }

    public function testARolledBackPlacementLeavesNoSummary(): void
    {
        $this->connection->beginTransaction();
        $this->placeAsCustomer('key-summary-4');
        $this->connection->rollBack();

        self::assertSame([], $this->summaries());
    }

    private function placeAsCustomer(string $key): PlacedOrder
    {
        [$variant] = OrderingFixture::variant($this->connection, 10_000, 5);
        $this->customerLines = [[$variant, 1]];
        CartFixture::cart($this->connection, customerId: self::CUSTOMER, lines: $this->customerLines);

        $placed = $this->place(CartReference::customer(self::CUSTOMER), $key, $this->customerLines);
        self::assertFalse($placed->replayed);

        return $placed;
    }

    /**
     * @param list<array{string, int}> $expectedLines the lines the checkout showed: variant id and quantity
     */
    private function place(CartReference $cart, string $key, array $expectedLines): PlacedOrder
    {
        $placed = $this->bus->dispatch(new PlaceOrder($cart, 'anna@example.com', 'Anna Nowak', '600100200', 'Długa 1', '00-950', 'Warszawa', OrderingFixture::freeDeliveryMethod($this->connection), self::GROSS, OrderingFixture::expected($expectedLines), $key))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(PlacedOrder::class, $placed);

        return $placed;
    }

    private function pay(string $orderId, string $paymentId): MarkOrderPaidResult
    {
        $result = $this->bus->dispatch(new MarkOrderPaid($orderId, $paymentId, self::GROSS, 'PLN', new \DateTimeImmutable()))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(MarkOrderPaidResult::class, $result);

        return $result;
    }

    /**
     * @return list<list<mixed>>
     */
    private function summaries(): array
    {
        return $this->connection->fetchAllNumeric('SELECT customer_id, email, full_name, phone, order_count, paid_count, CAST(paid_gross AS int), CAST(last_order_id AS text) FROM ordering_customer ORDER BY customer_id');
    }
}
