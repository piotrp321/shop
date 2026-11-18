<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Identity\DemoUuid;
use App\DemoData\Orders\OrderGenerator;
use App\DemoData\Profile\DemoProfile;
use App\DemoData\Profile\RunContext;
use App\Inventory\Domain\Reservation\ReservationKey;
use App\Inventory\Domain\Stock\VariantId as StockVariantId;
use App\Inventory\Infrastructure\Persistence\Doctrine\DoctrineReservationRepository;
use App\Inventory\Infrastructure\Persistence\Doctrine\DoctrineStockItemRepository;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNumber;
use App\Ordering\Infrastructure\Persistence\Doctrine\DoctrineOrderRepository;
use App\Payment\Domain\OrderId as PaymentOrderId;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\RequestFingerprint as PaymentFingerprint;
use App\Payment\Infrastructure\Persistence\Doctrine\DoctrinePaymentRepository;
use App\Pricing\Domain\DiscountCode\OrderId as PricingOrderId;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeUsageRepository;
use App\Tests\Support\InMemory\RecordingDomainEventPublisher;
use App\Tests\Unit\DemoData\GeneratedOrderCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The order steps of the generator with the profile small (orders, stock, the number sequence), on shop_test in the
 * transaction of the test, after the steps of the catalog and the prices: the rows are the ones the generator computes,
 * read back through the repositories of Ordering, Payment, Pricing and Inventory, and the stock keeps its invariants.
 */
final class OrderStepsTest extends KernelTestCase
{
    use RunsDemoDataSteps;

    private const string NAMESPACE = '8d3b6f1e-5a27-4c90-b1e4-7f02a96c35d8';
    private const int ORDERS = 5_000;

    protected function setUp(): void
    {
        foreach (['catalog_product', 'ordering_order', 'payment_payment', 'inventory_stock_item', 'inventory_reservation', 'inventory_stock_movement', 'pricing_discount_code_usage'] as $table) {
            self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM '.$table)), 'shop_test has rows in '.$table.' that are not the ones of this test.');
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // The generated catalog of a test is large: give its chunks back, since the suite runs in one process.
        gc_collect_cycles();
        gc_mem_caches();
    }

    private static function context(): RunContext
    {
        return new RunContext(new DemoProfile('small', 20, 1_000, 2_000, self::ORDERS, 5), self::NAMESPACE, 500, new \DateTimeImmutable(self::ANCHOR));
    }

    public function testTheStepsEndWithCodeZeroFillTheTablesAndPublishNothing(): void
    {
        $messages = $this->int($this->connection()->fetchOne('SELECT count(*) FROM messenger_messages'));
        $this->generatedSteps();

        $this->orderSteps();

        $connection = $this->connection();
        self::assertSame(self::ORDERS, $this->int($connection->fetchOne('SELECT count(*) FROM ordering_order')));
        self::assertGreaterThan(self::ORDERS, $this->int($connection->fetchOne('SELECT count(*) FROM ordering_order_line')));
        self::assertGreaterThan(2 * self::ORDERS, $this->int($connection->fetchOne('SELECT count(*) FROM ordering_order_status_change')));
        self::assertSame($this->int($connection->fetchOne('SELECT count(*) FROM ordering_order WHERE paid_at IS NOT NULL')), $this->int($connection->fetchOne('SELECT count(*) FROM payment_payment')));
        self::assertSame($this->int($connection->fetchOne('SELECT count(*) FROM ordering_order WHERE discount_code IS NOT NULL')), $this->int($connection->fetchOne('SELECT count(*) FROM pricing_discount_code_usage')));
        self::assertSame($this->int($connection->fetchOne('SELECT count(*) FROM catalog_product_variant')), $this->int($connection->fetchOne('SELECT count(*) FROM inventory_stock_item WHERE product_id IS NOT NULL')));
        // No event, no scheduled message, no e-mail of an order.
        self::assertSame($messages, $this->int($connection->fetchOne('SELECT count(*) FROM messenger_messages')));
        self::assertSame(0, $this->int($connection->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name IN ('outbox', 'scheduled')")));
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM ordering_order_notification')));
    }

    public function testTheRowsAreTheOnesTheGeneratorComputesFromTheSeed(): void
    {
        $this->generatedSteps();
        $this->succeeds('demo-data:orders');

        $generator = OrderGenerator::forRun(new Dictionaries(self::directory()), new PersonDictionaries(self::directory()), self::context());
        $expected = $generator->part(7, new GeneratedOrderCatalog(self::context()));
        self::assertCount(500, $expected);
        foreach ($expected as $order) {
            $row = $this->connection()->fetchAssociative('SELECT * FROM ordering_order WHERE id = ?', [$order->id]);
            self::assertIsArray($row, $order->number);
            self::assertSame([$order->number, $order->status, $order->ownerType, $order->customerId, $order->email, $order->totalGross, $order->requestFingerprint, $order->discountCode], [$row['number'], $row['status'], $row['owner_type'], $row['customer_id'], $row['email'], $this->int($row['total_gross']), $row['request_fingerprint'], $row['discount_code']]);
            self::assertSame($order->placedAt->getTimestamp(), new \DateTimeImmutable($this->text($row['placed_at']))->getTimestamp());
            $lines = $this->connection()->fetchAllAssociative('SELECT id, variant_id, unit_gross, line_gross, discount_gross, promotion_id FROM ordering_order_line WHERE order_id = ? ORDER BY position', [$order->id]);
            self::assertSame(
                array_map(static fn ($line): array => [$line->id, $line->variantId, $line->unitGross, $line->lineGross, $line->discountGross, $line->promotionId], $order->lines),
                array_map(fn (array $line): array => [$line['id'], $line['variant_id'], $this->int($line['unit_gross']), $this->int($line['line_gross']), $this->int($line['discount_gross']), $line['promotion_id']], $lines),
            );
            self::assertSame(\count($order->history), $this->int($this->connection()->fetchOne('SELECT count(*) FROM ordering_order_status_change WHERE order_id = ?', [$order->id])));
        }
    }

    public function testTwoRunsGiveTheSameRowsButOtherTokensAndKeys(): void
    {
        $this->generatedSteps();
        $this->orderSteps();
        $first = $this->orderChecksums();
        $secrets = $this->secrets();

        $this->connection()->executeStatement('TRUNCATE ordering_order_notification, ordering_order_status_change, ordering_order_line, ordering_order, payment_payment, pricing_discount_code_usage, inventory_reservation_line, inventory_reservation, inventory_stock_movement, inventory_stock_item');
        $this->orderSteps();

        self::assertSame($first, $this->orderChecksums());
        $again = $this->secrets();
        self::assertCount(\count($secrets), $again);
        self::assertSame([], array_intersect($secrets, $again), 'No guest token or idempotency key repeats.');
    }

    public function testAPlacementRebuiltFromTheSeedFindsNoOrder(): void
    {
        $this->generatedSteps();
        $this->succeeds('demo-data:orders');
        $orders = new DoctrineOrderRepository($this->entityManager());

        // Every value a request could compute from the public seed: the ids, numbers and names of the orders.
        foreach ($this->connection()->fetchAllAssociative('SELECT id, number FROM ordering_order ORDER BY number LIMIT 300') as $index => $row) {
            foreach ([$this->text($row['id']), $this->text($row['number']), DemoUuid::v5(self::NAMESPACE, 'order:'.$index), DemoUuid::v5(self::NAMESPACE, 'idempotency:'.$index)] as $candidate) {
                self::assertNull($orders->findByIdempotencyKey(IdempotencyKey::fromString($candidate)), $candidate);
            }
        }
        // The keys and the guest tokens are random: a UUID v4 and 32 random bytes.
        self::assertSame(0, $this->int($this->connection()->fetchOne("SELECT count(*) FROM ordering_order WHERE idempotency_key !~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'")));
        self::assertSame(0, $this->int($this->connection()->fetchOne("SELECT count(*) FROM ordering_order WHERE owner_type = 'guest' AND length(access_token) <> 43")));
    }

    public function testEveryOrderPaymentStockAndUsageReconstitutesThroughTheRepositories(): void
    {
        $this->generatedSteps();
        $this->orderSteps();
        $connection = $this->connection();
        $orders = new DoctrineOrderRepository($this->entityManager());
        $payments = new DoctrinePaymentRepository($this->entityManager());
        $usages = new DoctrineDiscountCodeUsageRepository($this->entityManager());
        $reservations = new DoctrineReservationRepository($connection);
        $stock = new DoctrineStockItemRepository($connection, new RecordingDomainEventPublisher());

        foreach ($connection->fetchAllAssociative('SELECT id, paid_at, discount_code, total_gross FROM ordering_order ORDER BY number') as $position => $row) {
            $id = $this->text($row['id']);
            $order = $orders->get(OrderId::fromString($id));
            self::assertSame($id, $order->id()->toString());
            if (null !== $row['paid_at']) {
                $payment = $payments->latestOfOrder(PaymentOrderId::fromString($id));
                self::assertNotNull($payment, $id);
                self::assertSame($this->int($row['total_gross']), $payment->amount()->minorUnits());
                self::assertTrue(PaymentFingerprint::of(PaymentOrderId::fromString($id), $payment->provider())->equals($payment->requestFingerprint()), 'The fingerprint is the one of Payment.');
            }
            if (null !== $row['discount_code']) {
                self::assertNotNull($usages->ofOrder(PricingOrderId::fromString($id)), $id);
            }
            if (0 === $position % 50) {
                $this->entityManager()->clear();
            }
        }

        $keys = array_map($this->text(...), $connection->fetchFirstColumn('SELECT reservation_key FROM inventory_reservation'));
        self::assertNotSame([], $keys);
        foreach ($keys as $key) {
            self::assertNotNull($reservations->find(ReservationKey::fromString($key)), $key);
        }
        $variants = array_map($this->text(...), $connection->fetchFirstColumn('SELECT variant_id FROM inventory_stock_item'));
        $found = 0;
        foreach (array_chunk($variants, 1_000) as $chunk) {
            $found += \count($stock->findMany(array_map(StockVariantId::fromString(...), $chunk)));
        }
        self::assertSame(\count($variants), $found);
        self::assertContains(ProviderCode::DemoCard->value, array_map($this->text(...), $connection->fetchFirstColumn('SELECT DISTINCT provider FROM payment_payment')));
    }

    public function testTheNumbersGrowWithThePlacementAndTheSequenceIsPastTheLastNumber(): void
    {
        $this->generatedSteps();
        $this->orderSteps();
        $connection = $this->connection();

        self::assertSame('R-'.OrderNumber::FIRST, $this->text($connection->fetchOne('SELECT number FROM ordering_order ORDER BY placed_at, number LIMIT 1')));
        self::assertSame(0, $this->int($connection->fetchOne(
            'SELECT count(*) FROM (SELECT placed_at, lag(placed_at) OVER (ORDER BY substr(number, 3)::bigint) AS previous FROM ordering_order) t WHERE placed_at < previous',
        )));
        $last = $this->int($connection->fetchOne('SELECT max(substr(number, 3)::bigint) FROM ordering_order'));
        self::assertSame(OrderNumber::FIRST + self::ORDERS - 1, $last);
        self::assertGreaterThan($last, $this->int($connection->fetchOne("SELECT nextval('ordering_order_number_seq')")));
    }

    public function testTheStockKeepsItsInvariantsAndEndsWithTheLastMovement(): void
    {
        $this->generatedSteps();
        $this->orderSteps();
        $connection = $this->connection();
        $window = "?::timestamptz - interval '90 days'";

        // Every movement keeps physical >= reserved >= 0, and the last one of a variant is its stock.
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM inventory_stock_movement WHERE physical_after < reserved_after OR reserved_after < 0')));
        self::assertSame(0, $this->int($connection->fetchOne(
            'SELECT count(*) FROM inventory_stock_item s JOIN LATERAL (SELECT * FROM inventory_stock_movement m WHERE m.variant_id = s.variant_id ORDER BY m.occurred_at DESC, m.id DESC LIMIT 1) l ON true
             WHERE (l.physical_after, l.reserved_after) IS DISTINCT FROM (s.physical, s.reserved)',
        )));
        // The running sums of the deltas are the values after each movement.
        self::assertSame(0, $this->int($connection->fetchOne(
            'SELECT count(*) FROM (SELECT physical_after, reserved_after, sum(physical_delta) OVER w AS physical, sum(reserved_delta) OVER w AS reserved FROM inventory_stock_movement
             WINDOW w AS (PARTITION BY variant_id ORDER BY occurred_at, id)) t WHERE physical <> physical_after OR reserved <> reserved_after',
        )));
        // Reserved is the sum of the lines of the active reservations, and every paid order has one.
        self::assertSame(0, $this->int($connection->fetchOne(
            "SELECT count(*) FROM inventory_stock_item s WHERE s.reserved <> coalesce((SELECT sum(l.quantity) FROM inventory_reservation_line l JOIN inventory_reservation r ON r.reservation_key = l.reservation_key WHERE r.status = 'active' AND l.variant_id = s.variant_id), 0)",
        )));
        self::assertSame(0, $this->int($connection->fetchOne("SELECT count(*) FROM ordering_order o WHERE o.status = 'paid' AND NOT EXISTS (SELECT 1 FROM inventory_reservation r WHERE r.reservation_key = o.id::text AND r.status = 'active')")));
        self::assertGreaterThan(0, $this->int($connection->fetchOne("SELECT count(*) FROM inventory_reservation WHERE status = 'active'")));
        // Ended reservations and movements only for the orders of the last 90 days, with the keys and sources of the application.
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM inventory_reservation r JOIN ordering_order o ON o.id::text = r.reservation_key WHERE o.placed_at < '.$window, [self::ANCHOR])));
        self::assertSame(0, $this->int($connection->fetchOne("SELECT count(*) FROM inventory_stock_movement WHERE type <> 'receipt' AND (source <> 'system' OR NOT EXISTS (SELECT 1 FROM inventory_reservation r WHERE r.reservation_key = reference))")));
        self::assertSame(0, $this->int($connection->fetchOne("SELECT count(*) FROM inventory_stock_movement WHERE type = 'receipt' AND (source <> 'seed' OR reference <> 'opening:' || variant_id::text OR occurred_at <> ".$window.')', [self::ANCHOR])));
        self::assertSame(0, $this->int($connection->fetchOne("SELECT count(*) FROM inventory_reservation r WHERE NOT EXISTS (SELECT 1 FROM ordering_order o WHERE o.id::text = r.reservation_key AND r.status = CASE WHEN o.status = 'paid' THEN 'active' WHEN o.status = 'cancelled' THEN 'released' ELSE 'committed' END)")));
        // The opening balance is the final stock and what was committed in the window.
        self::assertSame(0, $this->int($connection->fetchOne(
            "SELECT count(*) FROM inventory_stock_item s JOIN inventory_stock_movement o ON o.variant_id = s.variant_id AND o.type = 'receipt'
             WHERE o.physical_delta <> s.physical + coalesce((SELECT -sum(m.physical_delta) FROM inventory_stock_movement m WHERE m.variant_id = s.variant_id AND m.type = 'commit'), 0)",
        )));
    }

    public function testAboutEightPercentOfTheVariantsWithoutOrdersInTheWindowHaveNoStock(): void
    {
        $this->generatedSteps();
        $this->orderSteps();
        $connection = $this->connection();

        $quiet = $this->int($connection->fetchOne("SELECT count(*) FROM inventory_stock_item s WHERE NOT EXISTS (SELECT 1 FROM inventory_stock_movement m WHERE m.variant_id = s.variant_id AND m.type <> 'receipt')"));
        $empty = $this->int($connection->fetchOne("SELECT count(*) FROM inventory_stock_item s WHERE s.physical = 0 AND NOT EXISTS (SELECT 1 FROM inventory_stock_movement m WHERE m.variant_id = s.variant_id AND m.type <> 'receipt')"));
        self::assertGreaterThan(1_000, $quiet);
        self::assertEqualsWithDelta(0.08, $empty / $quiet, 0.02);
        // A variant without stock has no movement (an empty record), one with stock its opening receipt.
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM inventory_stock_item s WHERE s.physical = 0 AND EXISTS (SELECT 1 FROM inventory_stock_movement m WHERE m.variant_id = s.variant_id)')));
        self::assertSame(0, $this->int($connection->fetchOne("SELECT count(*) FROM inventory_stock_item s WHERE s.physical > 0 AND NOT EXISTS (SELECT 1 FROM inventory_stock_movement m WHERE m.variant_id = s.variant_id AND m.type = 'receipt')")));
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM inventory_stock_item s JOIN catalog_product_variant v ON v.id = s.variant_id WHERE s.product_id IS DISTINCT FROM v.product_id')));
    }

    /**
     * @return array<string, string>
     */
    private function orderChecksums(): array
    {
        $tables = [
            'ordering_order' => ['t.id', "to_jsonb(t) - 'access_token' - 'idempotency_key'"],
            'ordering_order_line' => ['t.id', 't'],
            'ordering_order_status_change' => ['t.order_id, t.position', 't'],
            'payment_payment' => ['t.id', "to_jsonb(t) - 'idempotency_key'"],
            'pricing_discount_code_usage' => ['t.id', 't'],
            'inventory_stock_item' => ['t.variant_id', 't'],
            'inventory_reservation' => ['t.reservation_key', 't'],
            'inventory_reservation_line' => ['t.reservation_key, t.variant_id', 't'],
            'inventory_stock_movement' => ['t.id', 't'],
        ];
        $sums = [];
        foreach ($tables as $table => [$order, $row]) {
            $sums[$table] = $this->text($this->connection()->fetchOne(\sprintf("SELECT coalesce(md5(string_agg((%s)::text, '|' ORDER BY %s)), 'empty') FROM %s t", $row, $order, $table)));
        }
        self::assertNotContains('empty', $sums);

        return $sums;
    }

    /**
     * @return list<string>
     */
    private function secrets(): array
    {
        return array_map($this->text(...), $this->connection()->fetchFirstColumn(
            'SELECT idempotency_key FROM ordering_order UNION ALL SELECT access_token FROM ordering_order WHERE access_token IS NOT NULL UNION ALL SELECT idempotency_key FROM payment_payment',
        ));
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private static function directory(): string
    {
        return \dirname(__DIR__, 3).'/fixtures/demo-data';
    }
}
