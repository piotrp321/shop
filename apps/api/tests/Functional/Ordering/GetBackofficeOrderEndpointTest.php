<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\Command\ChangeOrderStatus;
use App\Ordering\Application\Command\CompleteRefund;
use App\Ordering\Domain\StaffTransition;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/v1/admin/orders/{orderId} on PostgreSQL (shop_test, rolled back after each test).
 */
final class GetBackofficeOrderEndpointTest extends WebTestCase
{
    use BackofficeOrderEndpointTestTrait;

    private const string UNKNOWN_ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000ff';

    public function testThePaidOrderWithItsContactAddressLinesTotalsPaymentHistoryAndVersion(): void
    {
        [$orderId, $variant] = $this->paidOrder('Jan.Kowalski@Example.com');
        $paymentId = $this->connection->fetchOne('SELECT payment_id FROM ordering_order WHERE id = ?', [$orderId]);

        $order = $this->getJson($this->path($orderId), self::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
        self::assertMatchesSchema('BackofficeOrder', $order);
        self::assertSame([$orderId, 'paid', 2], [$order['orderId'], $order['status'], $order['version']]);
        self::assertIsString($order['number']);
        self::assertMatchesRegularExpression('/^R-[1-9][0-9]{5,}$/D', $order['number']);
        self::assertSame(['Jan.Kowalski@Example.com', 'Jan Kowalski', '+48600100200'], [$order['email'], $order['fullName'], $order['phone']]);
        self::assertSame(['street' => 'Długa 1', 'postalCode' => '00-950', 'city' => 'Warszawa', 'country' => 'PL'], $order['shippingAddress']);
        self::assertIsArray($order['lines']);
        self::assertCount(1, $order['lines']);
        self::assertIsArray($order['lines'][0]);
        self::assertSame([$variant, 2, 12_300, 24_600], [$order['lines'][0]['variantId'], $order['lines'][0]['quantity'], $order['lines'][0]['unitGross'], $order['lines'][0]['lineGross']]);
        self::assertSame(['net' => 20_000, 'vat' => 4_600, 'gross' => 24_600, 'currency' => 'PLN'], $order['totals']);
        foreach (['placedAt', 'paymentDeadline', 'paidAt'] as $moment) {
            self::assertUtc($order[$moment]);
        }
        self::assertSame($paymentId, $order['paymentId']);
        self::assertSame([null, null, null, null, null], [$order['shippedAt'], $order['deliveredAt'], $order['cancelledAt'], $order['cancellationReason'], $order['returnedAt']]);
        self::assertIsArray($order['history']);
        self::assertSame(
            [[null, 'new', 'customer', null, null, null], ['new', 'paid', 'system', null, null, null]],
            array_map(static fn (mixed $change): array => \is_array($change) ? [$change['from'], $change['to'], $change['actorType'], $change['staffId'], $change['staffUsername'], $change['note']] : [], $order['history']),
            'oldest first',
        );
        self::assertNull($order['refund']);
        self::assertSame(['ship', 'cancel'], $order['allowedTransitions']);
        self::assertFalse($order['canCompleteRefund']);
    }

    public function testAnIdInUpperCaseIsTheSameOrder(): void
    {
        [$orderId] = $this->paidOrder();

        $order = $this->getJson($this->path(strtoupper($orderId)), self::staff(['support']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($orderId, $order['orderId']);
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, list<string>}>
     */
    public static function rolesAndTransitions(): iterable
    {
        yield 'admin' => [['admin'], ['ship', 'cancel'], ['deliver', 'return']];
        yield 'warehouse' => [['warehouse'], ['ship'], ['deliver']];
        yield 'support' => [['support'], ['cancel'], ['return']];
        yield 'support and warehouse' => [['support', 'warehouse'], ['ship', 'cancel'], ['deliver', 'return']];
        yield 'warehouse and marketing' => [['warehouse', 'marketing'], ['ship'], ['deliver']];
    }

    /**
     * @param list<string> $roles
     * @param list<string> $whenPaid
     * @param list<string> $whenShipped
     */
    #[DataProvider('rolesAndTransitions')]
    public function testTheAllowedTransitionsAreTheOnesOfTheStatusTheStaffMemberMayMake(array $roles, array $whenPaid, array $whenShipped): void
    {
        [$orderId] = $this->paidOrder();
        self::assertSame($whenPaid, $this->getJson($this->path($orderId), self::staff($roles))['allowedTransitions']);

        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Ship, 2, null, 'kc-staff-1', 'marek'));

        self::assertSame($whenShipped, $this->getJson($this->path($orderId), self::staff($roles))['allowedTransitions']);
    }

    public function testThePendingRefundWithItsDueDateOverdueAndWhoMayCompleteIt(): void
    {
        [$orderId] = $this->paidOrder();
        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Cancel, 2, 'Klient zrezygnował', 'kc-staff-1', 'anna'));

        $order = $this->getJson($this->path($orderId), self::staff(['support']));

        self::assertSame(['cancelled', 'staff', 3, []], [$order['status'], $order['cancellationReason'], $order['version'], $order['allowedTransitions']]);
        self::assertUtc($order['cancelledAt']);
        self::assertIsArray($order['refund']);
        self::assertMatchesSchema('BackofficeOrderRefund', $order['refund']);
        self::assertSame([24_600, 'PLN', false, null, null, null, null], [$order['refund']['amount'], $order['refund']['currency'], $order['refund']['overdue'], $order['refund']['completedAt'], $order['refund']['completedByStaffId'], $order['refund']['completedByUsername'], $order['refund']['note']]);
        self::assertUtc($order['refund']['dueAt']);
        self::assertIsString($order['cancelledAt']);
        self::assertIsString($order['refund']['dueAt']);
        self::assertSame(14 * 86_400, new \DateTimeImmutable($order['refund']['dueAt'])->getTimestamp() - new \DateTimeImmutable($order['cancelledAt'])->getTimestamp());
        self::assertIsArray($order['history']);
        self::assertSame(['paid', 'cancelled', 'staff', 'kc-staff-1', 'anna', 'Klient zrezygnował'], array_values(array_intersect_key((array) end($order['history']), array_flip(['from', 'to', 'actorType', 'staffId', 'staffUsername', 'note']))));
        self::assertTrue($order['canCompleteRefund']);
        self::assertTrue($this->getJson($this->path($orderId), self::staff(['admin']))['canCompleteRefund']);
        self::assertFalse($this->getJson($this->path($orderId), self::staff(['warehouse']))['canCompleteRefund']);

        $this->connection->executeStatement("UPDATE ordering_order SET refund_due_at = now() - interval '1 minute' WHERE id = ?", [$orderId]);

        $overdue = $this->getJson($this->path($orderId), self::staff(['support']))['refund'];
        self::assertIsArray($overdue);
        self::assertTrue($overdue['overdue']);
    }

    public function testACompletedRefundShowsItsCompletionAndCanNoLongerBeCompleted(): void
    {
        [$orderId] = $this->paidOrder();
        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Ship, 2, null, 'kc-staff-1', 'marek'));
        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Return, 3, 'Paczka wróciła', 'kc-staff-1', 'anna'));
        $this->dispatch(new CompleteRefund($orderId, 4, 'Przelew wysłany', 'kc-staff-2', 'bartek'));
        $this->connection->executeStatement("UPDATE ordering_order SET refund_due_at = now() - interval '1 day' WHERE id = ?", [$orderId]);

        $order = $this->getJson($this->path($orderId), self::staff(['admin']));

        self::assertSame(['returned', 5, []], [$order['status'], $order['version'], $order['allowedTransitions']]);
        foreach (['shippedAt', 'returnedAt'] as $moment) {
            self::assertUtc($order[$moment]);
        }
        self::assertNull($order['deliveredAt'], 'returned from shipped, never delivered');
        self::assertIsArray($order['refund']);
        self::assertSame([false, 'kc-staff-2', 'bartek', 'Przelew wysłany'], [$order['refund']['overdue'], $order['refund']['completedByStaffId'], $order['refund']['completedByUsername'], $order['refund']['note']]);
        self::assertUtc($order['refund']['completedAt']);
        self::assertFalse($order['canCompleteRefund']);
        self::assertIsArray($order['history']);
        self::assertSame(['new', 'paid', 'shipped', 'returned'], array_column($order['history'], 'to'), 'the completion of a refund adds no entry');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownOrders(): iterable
    {
        yield 'an unknown order' => [self::UNKNOWN_ORDER];
        yield 'an id that is no UUID' => ['R-100001'];
        yield 'a UUID with a suffix' => [self::UNKNOWN_ORDER.'0'];
    }

    #[DataProvider('unknownOrders')]
    public function testAnUnknownOrderIsA404(string $orderId): void
    {
        $this->assertProblem(404, $this->getJson($this->path($orderId), self::staff(['admin'])));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function nonViewers(): iterable
    {
        yield 'marketing' => [['marketing']];
        yield 'no staff role' => [[]];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('nonViewers')]
    public function testMarketingAndStaffWithoutARoleAreForbiddenAlsoForAnUnknownOrder(array $roles): void
    {
        [$orderId] = $this->paidOrder();

        $this->assertProblem(403, $this->getJson($this->path($orderId), self::staff($roles)));
        self::assertStringNotContainsString('Kowalski', (string) $this->response()->getContent());
        $this->assertProblem(403, $this->getJson($this->path(self::UNKNOWN_ORDER), self::staff($roles)));
    }

    public function testATokenIsRequiredAndACustomerTokenIsNotAccepted(): void
    {
        [$orderId] = $this->paidOrder();

        $this->assertUnauthorized($this->getJson($this->path($orderId), null));
        $this->assertUnauthorized($this->getJson($this->path($orderId), TestTokenFactory::customer()));
    }

    private function path(string $orderId): string
    {
        return '/api/v1/admin/orders/'.$orderId;
    }
}
