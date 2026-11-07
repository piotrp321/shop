<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\Command\ChangeOrderStatus;
use App\Ordering\Domain\StaffTransition;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/v1/admin/orders/{orderId}/refund-completion on PostgreSQL (shop_test, rolled back after each test): the
 * completion of a pending refund, the rule of the roles of the voter before the body, then 404 and 409.
 */
final class CompleteRefundEndpointTest extends WebTestCase
{
    use BackofficeOrderEndpointTestTrait;

    private const string UNKNOWN_ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000ff';
    private const string CONFLICT_PREFIX = 'tag:piotrp321.github.io,2026:shop/problems/';

    public function testSupportCompletesThePendingRefund(): void
    {
        $orderId = $this->cancelledOrder();

        $order = $this->complete($orderId, ['version' => 3, 'note' => ' Przelew wysłany '], self::staff(['support'], 'bartek'));

        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
        self::assertMatchesSchema('BackofficeOrder', $order);
        self::assertSame(['cancelled', 4, false], [$order['status'], $order['version'], $order['canCompleteRefund']]);
        self::assertIsArray($order['refund']);
        self::assertSame([TestTokenFactory::STAFF_ID, 'bartek', 'Przelew wysłany', false], [$order['refund']['completedByStaffId'], $order['refund']['completedByUsername'], $order['refund']['note'], $order['refund']['overdue']]);
        self::assertUtc($order['refund']['completedAt']);
        self::assertSame([4, 'bartek'], $this->connection->fetchNumeric('SELECT version, refund_completed_by_username FROM ordering_order WHERE id = ?', [$orderId]));
    }

    public function testAnAdministratorCompletesItWithoutANote(): void
    {
        $orderId = $this->cancelledOrder();

        $order = $this->complete($orderId, ['version' => 3, 'note' => null], self::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($order['refund']);
        self::assertNull($order['refund']['note']);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function nonRefunders(): iterable
    {
        yield 'warehouse' => [['warehouse']];
        yield 'marketing' => [['marketing']];
        yield 'warehouse and marketing' => [['warehouse', 'marketing']];
        yield 'no staff role' => [[]];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('nonRefunders')]
    public function testOnlyAdminAndSupportCompleteRefundsAndTheRoleIsCheckedFirst(array $roles): void
    {
        $orderId = $this->cancelledOrder();

        $this->assertProblem(403, $this->complete($orderId, ['version' => 3, 'note' => null], self::staff($roles)));
        $this->assertProblem(403, $this->complete($orderId, '{not json', self::staff($roles)));
        $this->assertProblem(403, $this->complete($orderId, ['version' => 'x'], self::staff($roles)));
        $this->assertProblem(403, $this->complete($orderId, 'version=3', self::staff($roles), 'text/plain'));
        $this->assertProblem(403, $this->complete(self::UNKNOWN_ORDER, ['version' => 3, 'note' => null], self::staff($roles)));

        self::assertSame([3, null], $this->connection->fetchNumeric('SELECT version, refund_completed_at FROM ordering_order WHERE id = ?', [$orderId]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownOrders(): iterable
    {
        yield 'an unknown order' => [self::UNKNOWN_ORDER];
        yield 'an id that is no UUID' => ['R-100001'];
    }

    #[DataProvider('unknownOrders')]
    public function testAnUnknownOrderIsA404BeforeAnyConflict(string $orderId): void
    {
        $this->assertProblem(404, $this->complete($orderId, ['version' => 99, 'note' => null], self::staff(['support'])));
    }

    public function testAnOrderWithoutARefundIsAConflict(): void
    {
        [$orderId] = $this->paidOrder();

        $this->assertConflict(self::CONFLICT_PREFIX.'refund-not-pending', 'To zamówienie nie ma zwrotu do wykonania.', $this->complete($orderId, ['version' => 2, 'note' => null], self::staff(['support'])));
        self::assertSame([2], $this->connection->fetchFirstColumn('SELECT version FROM ordering_order WHERE id = ?', [$orderId]));
    }

    public function testACompletedRefundIsNotPendingAndARepeatIsAVersionConflict(): void
    {
        $orderId = $this->cancelledOrder();
        $this->complete($orderId, ['version' => 3, 'note' => null], self::staff(['support']));
        self::assertResponseStatusCodeSame(200);

        $this->assertConflict('about:blank', 'Zamówienie zostało w międzyczasie zmienione.', $this->complete($orderId, ['version' => 3, 'note' => null], self::staff(['support'])));
        $this->assertConflict(self::CONFLICT_PREFIX.'refund-not-pending', 'To zamówienie nie ma zwrotu do wykonania.', $this->complete($orderId, ['version' => 4, 'note' => null], self::staff(['support'])));
        self::assertSame([4], $this->connection->fetchFirstColumn('SELECT version FROM ordering_order WHERE id = ?', [$orderId]));
    }

    public function testAStaleVersionIsAConflictAndChangesNothing(): void
    {
        $orderId = $this->cancelledOrder();

        $this->assertConflict('about:blank', 'Zamówienie zostało w międzyczasie zmienione.', $this->complete($orderId, ['version' => 2, 'note' => null], self::staff(['admin'])));
        self::assertSame([3, null], $this->connection->fetchNumeric('SELECT version, refund_completed_at FROM ordering_order WHERE id = ?', [$orderId]));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'no version' => [['note' => null], 'version'];
        yield 'no note' => [['version' => 3], 'note'];
        yield 'a null version' => [['version' => null, 'note' => null], 'version'];
        yield 'version zero' => [['version' => 0, 'note' => null], 'version'];
        yield 'a version as a string' => [['version' => '3', 'note' => null], 'version'];
        yield 'a note that is no string' => [['version' => 3, 'note' => false], 'note'];
        yield 'a note over 500 characters' => [['version' => 3, 'note' => str_repeat('a', 501)], 'note'];
        yield 'a note of two lines' => [['version' => 3, 'note' => "Przelew\r\nwysłany"], 'note'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIsA422WithItsViolation(array $body, string $path): void
    {
        $orderId = $this->cancelledOrder();

        self::assertSame([$path], $this->violationPaths(422, $this->complete($orderId, $body, self::staff(['support']))));
        self::assertSame([3, null], $this->connection->fetchNumeric('SELECT version, refund_completed_at FROM ordering_order WHERE id = ?', [$orderId]));
    }

    public function testABodyThatIsNoJsonIsA400AndAnotherMediaTypeA415(): void
    {
        $orderId = $this->cancelledOrder();

        $this->assertProblem(400, $this->complete($orderId, '{"version": 3', self::staff(['support'])));
        $this->assertProblem(415, $this->complete($orderId, 'version=3', self::staff(['support']), 'text/plain'));
    }

    public function testATokenIsRequiredAndACustomerTokenIsNotAccepted(): void
    {
        $orderId = $this->cancelledOrder();

        $this->assertUnauthorized($this->complete($orderId, ['version' => 3, 'note' => null], null));
        $this->assertUnauthorized($this->complete($orderId, ['version' => 3, 'note' => null], TestTokenFactory::customer()));
    }

    /**
     * A paid order cancelled by staff: version 3, a pending refund.
     */
    private function cancelledOrder(): string
    {
        [$orderId] = $this->paidOrder();
        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Cancel, 2, 'Brak towaru', 'kc-staff-1', 'anna'));
        $this->entityManager->clear();

        return $orderId;
    }

    /**
     * @param array<mixed>|string $body
     *
     * @return array<mixed>
     */
    private function complete(string $orderId, array|string $body, ?string $token, string $contentType = 'application/json'): array
    {
        return $this->postJson('/api/v1/admin/orders/'.$orderId.'/refund-completion', $body, $token, $contentType);
    }
}
