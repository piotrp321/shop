<?php

declare(strict_types=1);

namespace App\Tests\Functional\DemoData;

use App\Tests\Integration\DemoData\RunsDemoDataSteps;
use App\Tests\Support\Security\TestTokenFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The generated orders and stock on the real paths of the shop (profile small): the order list of the panel with its
 * filters, the detail with the history and the payment, shipping and cancelling a generated paid order through the API
 * of the panel, the order history of a generated customer (a token whose sub is the generated account) with the payment
 * of an order, and the price and stock history of a variant. The anchor is the start of today (the API reads the real
 * clock); the steps run in the process of the test and its transaction.
 */
final class GeneratedOrdersOnTheRealPathsTest extends WebTestCase
{
    use RunsDemoDataSteps;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM ordering_order')), 'shop_test has orders that are not the ones of this test.');

        $anchor = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        foreach (['demo-data:categories', 'demo-data:products', 'demo-data:prices', 'demo-data:promotions', 'demo-data:orders', 'demo-data:stock', 'demo-data:order-numbers'] as $step) {
            $this->succeeds($step, ['--anchor' => $anchor->format(\DATE_ATOM)]);
        }
    }

    public function testThePanelListsFiltersAndShowsAGeneratedOrderWithItsHistoryAndPayment(): void
    {
        $staff = TestTokenFactory::staff(['support']);
        $delivered = $this->get('/api/v1/admin/orders?status=delivered', $staff);
        $statuses = $this->column($delivered, 'items', 'status');
        self::assertNotSame([], $statuses);
        foreach ($statuses as $status) {
            self::assertSame('delivered', $status);
        }

        $row = $this->connection()->fetchAssociative("SELECT id, number FROM ordering_order WHERE status = 'returned' AND refund_completed_at IS NOT NULL ORDER BY number LIMIT 1");
        self::assertIsArray($row);
        $found = $this->get('/api/v1/admin/orders?q='.$this->text($row['number']), $staff);
        self::assertSame([$this->text($row['id'])], $this->column($found, 'items', 'orderId'));

        $order = $this->get('/api/v1/admin/orders/'.$this->text($row['id']), $staff);
        self::assertSame('returned', $order['status']);
        self::assertSame(['new', 'paid', 'shipped', 'delivered', 'returned'], $this->column($order, 'history', 'to'));
        self::assertSame(['customer', 'system', 'staff', 'staff', 'staff'], $this->column($order, 'history', 'actorType'));
        self::assertNotNull($order['paidAt']);
        self::assertNotNull($order['paymentId']);
        self::assertIsArray($order['refund']);
        self::assertNotNull($order['refund']['completedAt']);
    }

    public function testStaffShipAndCancelGeneratedPaidOrders(): void
    {
        $paid = $this->connection()->fetchAllAssociative("SELECT id, version FROM ordering_order WHERE status = 'paid' ORDER BY number LIMIT 2");
        self::assertCount(2, $paid, 'The last days have paid orders.');

        $shipped = $this->post('/api/v1/admin/orders/'.$this->text($paid[0]['id']).'/status-changes', ['transition' => 'ship', 'version' => $this->int($paid[0]['version']), 'note' => 'Kurier', 'trackingNumber' => null], TestTokenFactory::staff(['warehouse']));
        self::assertSame('shipped', $shipped['status']);
        self::assertSame('committed', $this->connection()->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$this->text($paid[0]['id'])]));

        $reservedBefore = $this->reservedOf($this->text($paid[1]['id']));
        $cancelled = $this->post('/api/v1/admin/orders/'.$this->text($paid[1]['id']).'/status-changes', ['transition' => 'cancel', 'version' => $this->int($paid[1]['version']), 'note' => 'Klient zrezygnował', 'trackingNumber' => null], TestTokenFactory::staff(['support']));
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('released', $this->connection()->fetchOne('SELECT status FROM inventory_reservation WHERE reservation_key = ?', [$this->text($paid[1]['id'])]));
        self::assertLessThan($reservedBefore, $this->reservedOf($this->text($paid[1]['id'])));
    }

    public function testAGeneratedCustomerSeesTheirOrdersAndThePaymentOfOne(): void
    {
        $customer = $this->text($this->connection()->fetchOne("SELECT customer_id FROM ordering_order WHERE owner_type = 'customer' GROUP BY customer_id HAVING count(*) BETWEEN 2 AND 10 ORDER BY customer_id LIMIT 1"));
        $token = TestTokenFactory::customer(['sub' => $customer]);
        $expected = array_map($this->text(...), $this->connection()->fetchFirstColumn('SELECT number FROM ordering_order WHERE customer_id = ? ORDER BY placed_at DESC, number DESC', [$customer]));

        $list = $this->get('/api/v1/me/orders', $token);

        self::assertSame($expected, $this->column($list, 'items', 'number'));
        $paid = $this->text($this->connection()->fetchOne('SELECT id FROM ordering_order WHERE customer_id = ? AND paid_at IS NOT NULL ORDER BY number LIMIT 1', [$customer]));
        $payment = $this->get('/api/v1/me/orders/'.$paid.'/payment', $token);
        self::assertFalse($payment['canStartPayment']);
        self::assertIsArray($payment['latestPayment']);
        self::assertSame('succeeded', $payment['latestPayment']['status']);
    }

    public function testThePriceAndStockHistoryOfAGeneratedVariant(): void
    {
        $variant = $this->text($this->connection()->fetchOne("SELECT variant_id FROM inventory_stock_movement WHERE type = 'commit' ORDER BY variant_id LIMIT 1"));

        $stock = $this->get('/api/v1/admin/variants/'.$variant.'/stock-history', TestTokenFactory::staff(['warehouse']));
        $types = $this->column($stock, 'items', 'type');
        self::assertContains('receipt', $types);
        self::assertContains('reservation', $types);
        self::assertContains('commit', $types);

        $prices = $this->get('/api/v1/admin/variants/'.$variant.'/price-history', TestTokenFactory::staff(['admin']));
        self::assertNotSame([], $prices['items']);
    }

    /**
     * @param array<mixed> $decoded
     *
     * @return list<mixed> the values of the key in the rows of the list
     */
    private function column(array $decoded, string $list, string $key): array
    {
        $rows = $decoded[$list] ?? null;
        self::assertIsArray($rows);
        $values = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $values[] = $row[$key] ?? null;
        }

        return $values;
    }

    private function reservedOf(string $orderId): int
    {
        return $this->int($this->connection()->fetchOne('SELECT sum(s.reserved) FROM inventory_stock_item s JOIN ordering_order_line l ON l.variant_id = s.variant_id WHERE l.order_id = ?', [$orderId]));
    }

    /**
     * @return array<mixed>
     */
    private function get(string $path, string $token): array
    {
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decoded();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function post(string $path, array $body, string $token): array
    {
        $this->client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], content: json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $this->decoded();
    }

    /**
     * @return array<mixed>
     */
    private function decoded(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
