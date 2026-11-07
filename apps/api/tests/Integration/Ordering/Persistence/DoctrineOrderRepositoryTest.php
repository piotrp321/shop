<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering\Persistence;

use App\Ordering\Domain\AccessToken;
use App\Ordering\Domain\CancellationReason;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\IdempotencyKeyReused;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderLine;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderNumber;
use App\Ordering\Domain\OrderOwner;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Domain\PaymentId;
use App\Ordering\Domain\PaymentResult;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\ProductId;
use App\Ordering\Domain\RequestFingerprint;
use App\Ordering\Domain\ShippingAddress;
use App\Ordering\Domain\VariantId;
use App\Ordering\Infrastructure\Persistence\Doctrine\DbalOrderNumbers;
use App\Ordering\Infrastructure\Persistence\Doctrine\DoctrineOrderRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * DoctrineOrderRepository and the Ordering schema on shop_test (rolled back after each test): the mapping (also of the
 * payment cycle), the CHECK constraints and unique indexes that refuse direct SQL breaking the rules, and the number sequence.
 */
final class DoctrineOrderRepositoryTest extends KernelTestCase
{
    private const string TOKEN = 'dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM';

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineOrderRepository $orders;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->orders = new DoctrineOrderRepository($entityManager);
    }

    public function testAnOrderIsStoredAndReadBackWithEveryCopy(): void
    {
        $order = $this->order('key-1', OrderOwner::customer('kc-sub-1'));
        $this->orders->add($order);
        $this->entityManager->clear();

        $stored = $this->orders->get($order->id());

        self::assertSame($order->number()->toString(), $stored->number()->toString());
        self::assertSame('kc-sub-1', $stored->owner()->customerId());
        self::assertSame(['jan@example.com', 'Jan Kowalski', '+48600100200'], [$stored->contact()->email()->toString(), $stored->contact()->fullName(), $stored->contact()->phone()]);
        self::assertSame(['Długa 1', '00-950', 'Warszawa', 'PL'], [$stored->shippingAddress()->street(), $stored->shippingAddress()->postalCode(), $stored->shippingAddress()->city(), $stored->shippingAddress()->country()]);
        self::assertSame(
            array_map(self::lineData(...), $order->lines()),
            array_map(self::lineData(...), $stored->lines()),
            'the lines in their positions with the copied names and prices',
        );
        self::assertSame([77_800, 63_626, 14_174], [$stored->totals()->gross()->amount(), $stored->totals()->net()->amount(), $stored->totals()->vat()->amount()]);
        self::assertSame('key-1', $stored->idempotencyKey()->toString());
        self::assertTrue($stored->isReplayOf($order->requestFingerprint()));
        self::assertEquals($order->placedAt(), $stored->placedAt());
        self::assertSame(1, $stored->version());
        self::assertSame([], $stored->releaseEvents());

        $row = $this->connection->fetchAssociative('SELECT owner_type, total_net, total_vat, total_gross, currency, country, request_fingerprint FROM ordering_order WHERE id = ?', [$order->id()->toString()]);
        self::assertSame(['owner_type' => 'customer', 'total_net' => 63_626, 'total_vat' => 14_174, 'total_gross' => 77_800, 'currency' => 'PLN', 'country' => 'PL', 'request_fingerprint' => $order->requestFingerprint()->toString()], $row);
        $line = $this->connection->fetchAssociative('SELECT position, quantity, unit_net, unit_vat, unit_gross, vat_rate_bp, tax_class, line_net, line_vat, line_gross FROM ordering_order_line WHERE order_id = ? ORDER BY position LIMIT 1', [$order->id()->toString()]);
        self::assertSame(['position' => 0, 'quantity' => 2, 'unit_net' => 30_000, 'unit_vat' => 6_900, 'unit_gross' => 36_900, 'vat_rate_bp' => 2300, 'tax_class' => 'standard', 'line_net' => 60_000, 'line_vat' => 13_800, 'line_gross' => 73_800], $line);
    }

    public function testAGuestOrderHasNoCustomer(): void
    {
        $order = $this->order('key-guest');
        $this->orders->add($order);
        $this->entityManager->clear();

        self::assertTrue($this->orders->get($order->id())->owner()->isGuest());
        self::assertSame(['guest', null], $this->connection->fetchNumeric('SELECT owner_type, customer_id FROM ordering_order WHERE id = ?', [$order->id()->toString()]));
    }

    public function testTheAccessTokenOfAGuestAndTheDeadlineAreStored(): void
    {
        $guest = $this->order('key-guest');
        $customer = $this->order('key-customer', OrderOwner::customer('kc-sub-1'));
        $this->orders->add($guest);
        $this->orders->add($customer);
        $this->entityManager->clear();

        $storedGuest = $this->orders->get($guest->id());
        $storedCustomer = $this->orders->get($customer->id());

        self::assertSame(self::TOKEN, $storedGuest->accessToken()?->toString());
        self::assertNull($storedCustomer->accessToken());
        self::assertEquals($guest->paymentDeadline(), $storedGuest->paymentDeadline());
        self::assertSame(OrderStatus::New, $storedGuest->status());
        self::assertNull($storedGuest->paidAt());
        self::assertNull($storedGuest->paymentId());
        self::assertNull($storedGuest->cancelledAt());
        self::assertNull($storedGuest->cancellationReason());
        self::assertSame(
            ['access_token' => self::TOKEN, 'deadline' => 1800],
            $this->connection->fetchAssociative('SELECT access_token, EXTRACT(EPOCH FROM payment_deadline - placed_at)::int AS deadline FROM ordering_order WHERE id = ?', [$guest->id()->toString()]),
        );
    }

    public function testSaveWritesThePaymentAndRaisesTheVersion(): void
    {
        $order = $this->order('key-1');
        $this->orders->add($order);
        $this->entityManager->clear();
        $stored = $this->orders->get($order->id());
        $paidAt = new \DateTimeImmutable('@'.(time() + 60));
        $paymentId = Uuid::v7()->toRfc4122();

        self::assertSame(PaymentResult::Paid, $stored->markPaid(PaymentId::fromString($paymentId), 77_800, 'PLN', $stored->placedAt(), $paidAt));
        $this->orders->save($stored);
        $this->entityManager->clear();

        $again = $this->orders->get($order->id());
        self::assertSame(OrderStatus::Paid, $again->status());
        self::assertEquals($paidAt, $again->paidAt());
        self::assertSame($paymentId, $again->paymentId()?->toString());
        self::assertSame(2, $again->version());
        self::assertSame('paid', $this->connection->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$order->id()->toString()]));
    }

    public function testSaveWritesTheCancellation(): void
    {
        $order = $this->order('key-1');
        $this->orders->add($order);
        $this->entityManager->clear();
        $stored = $this->orders->get($order->id());
        $cancelledAt = $stored->expiryDueAt();

        $stored->expireUnpaid($cancelledAt);
        $this->orders->save($stored);
        $this->entityManager->clear();

        $again = $this->orders->get($order->id());
        self::assertSame(OrderStatus::Cancelled, $again->status());
        self::assertEquals($cancelledAt, $again->cancelledAt());
        self::assertSame(CancellationReason::PaymentTimeout, $again->cancellationReason());
        self::assertSame(['cancelled', 'payment_timeout'], $this->connection->fetchNumeric('SELECT status, cancellation_reason FROM ordering_order WHERE id = ?', [$order->id()->toString()]));
    }

    public function testFindByIdempotencyKeyGivesTheOrderOfTheKeyOnly(): void
    {
        $order = $this->order('key-1');
        $this->orders->add($order);
        $this->entityManager->clear();

        self::assertSame($order->id()->toString(), $this->orders->findByIdempotencyKey(IdempotencyKey::fromString('key-1'))?->id()->toString());
        self::assertNull($this->orders->findByIdempotencyKey(IdempotencyKey::fromString('key-2')));
    }

    public function testAnUnknownOrderIsNotFound(): void
    {
        $this->expectException(OrderNotFound::class);

        $this->orders->get(OrderId::fromString(Uuid::v7()->toRfc4122()));
    }

    public function testASecondOrderWithTheKeyOfAnotherIsIdempotencyKeyReused(): void
    {
        $this->orders->add($this->order('key-1'));

        $this->expectException(IdempotencyKeyReused::class);

        $this->orders->add($this->order('key-1'));
    }

    public function testSaveOfAnOrderReadAtAnOlderVersionIsRefused(): void
    {
        $order = $this->order('key-1');
        $this->orders->add($order);
        $this->entityManager->clear();
        $stale = $this->orders->get($order->id());
        $this->connection->executeStatement('UPDATE ordering_order SET version = version + 1 WHERE id = ?', [$order->id()->toString()]);
        $this->entityManager->clear();

        $stale->expireUnpaid($stale->expiryDueAt());

        $this->expectException(OrderModifiedConcurrently::class);
        $this->orders->save($stale);
    }

    public function testTheNumberSequenceStartsAt100001AndCountsUp(): void
    {
        self::assertSame([100001, 100001, 1], $this->connection->fetchNumeric("SELECT start_value, min_value, increment_by FROM pg_sequences WHERE sequencename = 'ordering_order_number_seq'"));

        $numbers = new DbalOrderNumbers($this->connection);
        $first = $numbers->next()->toString();
        $second = $numbers->next()->toString();

        self::assertMatchesRegularExpression('/^R-1[0-9]{5,}$/', $first);
        self::assertGreaterThanOrEqual(OrderNumber::FIRST, (int) substr($first, 2));
        self::assertSame((int) substr($first, 2) + 1, (int) substr($second, 2));
    }

    /**
     * @return iterable<string, array{string, string}> the statement and the SQLSTATE PostgreSQL refuses it with
     */
    public static function rowsBreakingTheRules(): iterable
    {
        yield 'guest with a customer id' => ["UPDATE ordering_order SET customer_id = 'kc-sub-1' WHERE id = :id", '23514'];
        yield 'customer without a customer id' => ["UPDATE ordering_order SET owner_type = 'customer' WHERE id = :id", '23514'];
        yield 'guest without an access token' => ['UPDATE ordering_order SET access_token = NULL WHERE id = :id', '23514'];
        yield 'customer with an access token' => ["UPDATE ordering_order SET owner_type = 'customer', customer_id = 'kc-sub-1' WHERE id = :id", '23514'];
        yield 'no deadline' => ['UPDATE ordering_order SET payment_deadline = NULL WHERE id = :id', '23502'];
        yield 'paid without paid_at' => ["UPDATE ordering_order SET status = 'paid', payment_id = gen_random_uuid() WHERE id = :id", '23514'];
        yield 'paid without payment_id' => ["UPDATE ordering_order SET status = 'paid', paid_at = now() WHERE id = :id", '23514'];
        yield 'new with paid_at and payment_id' => ['UPDATE ordering_order SET paid_at = now(), payment_id = gen_random_uuid() WHERE id = :id', '23514'];
        yield 'cancelled without cancelled_at' => ["UPDATE ordering_order SET status = 'cancelled', cancellation_reason = 'payment_timeout' WHERE id = :id", '23514'];
        yield 'cancelled without a reason' => ["UPDATE ordering_order SET status = 'cancelled', cancelled_at = now() WHERE id = :id", '23514'];
        yield 'new with cancelled_at and a reason' => ["UPDATE ordering_order SET cancelled_at = now(), cancellation_reason = 'payment_timeout' WHERE id = :id", '23514'];
        yield 'net + vat is not gross' => ['UPDATE ordering_order SET total_gross = total_gross + 1 WHERE id = :id', '23514'];
        yield 'quantity 0' => ['UPDATE ordering_order_line SET quantity = 0 WHERE order_id = :id', '23514'];
        yield 'quantity 100' => ['UPDATE ordering_order_line SET quantity = 100 WHERE order_id = :id', '23514'];
        yield 'a number twice' => ['UPDATE ordering_order SET number = (SELECT number FROM ordering_order WHERE id <> :id LIMIT 1) WHERE id = :id', '23505'];
        yield 'a key twice' => ['UPDATE ordering_order SET idempotency_key = (SELECT idempotency_key FROM ordering_order WHERE id <> :id LIMIT 1) WHERE id = :id', '23505'];
        yield 'a position twice' => ['UPDATE ordering_order_line SET position = 0 WHERE order_id = :id', '23505'];
        yield 'a variant twice' => ['UPDATE ordering_order_line SET variant_id = (SELECT variant_id FROM ordering_order_line WHERE order_id = :id AND position = 0) WHERE order_id = :id', '23505'];
        yield 'a line of no order' => ['UPDATE ordering_order_line SET order_id = gen_random_uuid() WHERE order_id = :id', '23503'];
        yield 'an order deleted with its lines' => ['DELETE FROM ordering_order WHERE id = :id', '23503'];
    }

    #[DataProvider('rowsBreakingTheRules')]
    public function testDirectSqlBreakingTheRulesIsRefused(string $sql, string $sqlState): void
    {
        $this->orders->add($this->order('key-other'));
        $order = $this->order('key-1');
        $this->orders->add($order);

        try {
            $this->connection->executeStatement($sql, ['id' => $order->id()->toString()]);
            self::fail('Expected PostgreSQL to refuse the statement.');
        } catch (DriverException $refused) {
            self::assertSame($sqlState, $refused->getSQLState(), $refused->getMessage());
        }
    }

    private function order(string $key, ?OrderOwner $owner = null): Order
    {
        $contact = Contact::of(EmailAddress::fromString('jan@example.com'), 'Jan Kowalski', '600 100 200');
        $address = ShippingAddress::inPoland('Długa 1', '00-950', 'Warszawa');

        $owner ??= OrderOwner::guest();

        return Order::place(
            OrderId::fromString(Uuid::v7()->toRfc4122()),
            new DbalOrderNumbers($this->connection)->next(),
            $owner,
            $owner->isGuest() ? AccessToken::fromString(self::TOKEN) : null,
            $contact,
            $address,
            [
                OrderLine::of(VariantId::fromString(Uuid::v7()->toRfc4122()), ProductId::fromString(Uuid::v7()->toRfc4122()), 'Buty Asfalt', 'czarne, 42', 2, PriceCopy::of(30_000, 6_900, 36_900, 2300, 'standard', Currency::PLN)),
                OrderLine::of(VariantId::fromString(Uuid::v7()->toRfc4122()), ProductId::fromString(Uuid::v7()->toRfc4122()), 'Mapa Gorce', '', 1, PriceCopy::of(1_626, 374, 2_000, 2300, 'standard', Currency::PLN)),
                OrderLine::of(VariantId::fromString(Uuid::v7()->toRfc4122()), ProductId::fromString(Uuid::v7()->toRfc4122()), 'Książka', '', 1, PriceCopy::of(2_000, 0, 2_000, 0, 'zero', Currency::PLN)),
            ],
            IdempotencyKey::fromString($key),
            RequestFingerprint::of('guest:'.$key, $contact, $address, 77_800),
            new \DateTimeImmutable('@'.time()),
        );
    }

    /**
     * @return list<int|string>
     */
    private static function lineData(OrderLine $line): array
    {
        $unit = $line->unitPrice();

        return [$line->variantId()->toString(), $line->productId()->toString(), $line->productName(), $line->variantLabel(), $line->quantity(), $unit->net()->amount(), $unit->vat()->amount(), $unit->gross()->amount(), $unit->vatRateBp(), $unit->taxClass()];
    }
}
