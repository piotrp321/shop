<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\Command\ChangeOrderStatus;
use App\Ordering\Domain\StaffTransition;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /api/v1/admin/orders/{orderId}/status-changes on PostgreSQL (shop_test, rolled back after each test): the
 * transitions with their effects, the rule of the roles of the voter, the strict body, and the order of the checks
 * (401, 403 to see orders, 400/415/422 of the body, 403 of the transition, 404, 409).
 */
final class ChangeOrderStatusEndpointTest extends WebTestCase
{
    use BackofficeOrderEndpointTestTrait;

    private const string UNKNOWN_ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000ff';
    private const string CONFLICT_PREFIX = 'tag:piotrp321.github.io,2026:shop/problems/';

    public function testTheWarehouseShipsAPaidOrderAndTheStockLeaves(): void
    {
        [$orderId, $variant] = $this->paidOrder();

        $order = $this->change($orderId, ['transition' => 'ship', 'version' => 2, 'note' => ' Kurier DPD '], self::staff(['warehouse'], 'marek'));

        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
        self::assertMatchesSchema('BackofficeOrder', $order);
        self::assertSame(['shipped', 3, ['deliver'], false], [$order['status'], $order['version'], $order['allowedTransitions'], $order['canCompleteRefund']]);
        self::assertUtc($order['shippedAt']);
        self::assertIsArray($order['history']);
        self::assertSame(
            ['from' => 'paid', 'to' => 'shipped', 'actorType' => 'staff', 'staffId' => TestTokenFactory::STAFF_ID, 'staffUsername' => 'marek', 'note' => 'Kurier DPD'],
            array_diff_key((array) end($order['history']), ['occurredAt' => 1]),
            'the staff member of the token, the note trimmed',
        );
        self::assertSame([3, 0], OrderingFixture::stock($this->connection, $variant), 'the reservation is committed');
        self::assertSame(['shipped', 3], $this->stored($orderId));
    }

    public function testSupportCancelsAPaidOrderWithANoteAndARefundIsDue(): void
    {
        [$orderId, $variant] = $this->paidOrder();

        $order = $this->change($orderId, ['transition' => 'cancel', 'version' => 2, 'note' => 'Klient zrezygnował'], self::staff(['support']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['cancelled', 'staff', 3, [], true], [$order['status'], $order['cancellationReason'], $order['version'], $order['allowedTransitions'], $order['canCompleteRefund']]);
        self::assertIsArray($order['refund']);
        self::assertSame([24_600, false, null], [$order['refund']['amount'], $order['refund']['overdue'], $order['refund']['completedAt']]);
        self::assertSame([5, 0], OrderingFixture::stock($this->connection, $variant), 'the reservation is released');
    }

    public function testDeliverAndReturnByAnAdministrator(): void
    {
        [$orderId] = $this->paidOrder();
        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Ship, 2, null, 'kc-staff-1', 'marek'));

        $delivered = $this->change($orderId, ['transition' => 'deliver', 'version' => 3, 'note' => '   '], self::staff(['admin']));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['delivered', ['return']], [$delivered['status'], $delivered['allowedTransitions']]);
        self::assertIsArray($delivered['history']);
        self::assertNull(((array) end($delivered['history']))['note'], 'a blank note is no note');

        $returned = $this->change($orderId, ['transition' => 'return', 'version' => 4, 'note' => 'Zwrot towaru'], self::staff(['admin']));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['returned', 5, [], true], [$returned['status'], $returned['version'], $returned['allowedTransitions'], $returned['canCompleteRefund']]);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function forbiddenTransitions(): iterable
    {
        yield 'warehouse cancels' => [['warehouse'], 'cancel'];
        yield 'warehouse registers a return' => [['warehouse'], 'return'];
        yield 'support ships' => [['support'], 'ship'];
        yield 'support delivers' => [['support'], 'deliver'];
        foreach (['ship', 'deliver', 'cancel', 'return'] as $transition) {
            yield 'marketing '.$transition => [['marketing'], $transition];
            yield 'no staff role '.$transition => [[], $transition];
        }
        yield 'warehouse with marketing cancels' => [['warehouse', 'marketing'], 'cancel'];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('forbiddenTransitions')]
    public function testATransitionOfAnotherRoleIsForbiddenAndChangesNothing(array $roles, string $transition): void
    {
        [$orderId, $variant] = $this->paidOrder();

        $this->assertProblem(403, $this->change($orderId, ['transition' => $transition, 'version' => 2, 'note' => 'Notatka'], self::staff($roles)));

        self::assertSame(['paid', 2], $this->stored($orderId));
        self::assertSame([5, 2], OrderingFixture::stock($this->connection, $variant));
    }

    public function testMarketingIsForbiddenBeforeTheBodyIsRead(): void
    {
        $this->assertProblem(403, $this->change(self::UNKNOWN_ORDER, '{not json', self::staff(['marketing'])));
        $this->assertProblem(403, $this->change(self::UNKNOWN_ORDER, ['transition' => 'fly'], self::staff(['marketing'])));
        $this->assertProblem(403, $this->change(self::UNKNOWN_ORDER, 'transition=ship', self::staff(['marketing']), 'text/plain'));
    }

    public function testTheBodyIsCheckedBeforeTheRoleOfTheTransition(): void
    {
        [$orderId] = $this->paidOrder();

        self::assertSame(['note'], $this->violationPaths(422, $this->change($orderId, ['transition' => 'cancel', 'version' => 2, 'note' => null], self::staff(['warehouse']))));
        self::assertSame(['version'], $this->violationPaths(422, $this->change($orderId, ['transition' => 'ship', 'version' => 'x', 'note' => null], self::staff(['support']))));
        $this->assertProblem(400, $this->change($orderId, '{"transition": "ship"', self::staff(['support'])));
        $this->assertProblem(415, $this->change($orderId, 'transition=ship', self::staff(['support']), 'text/plain'));
    }

    public function testTheRoleOfTheTransitionIsCheckedBeforeTheOrderIsLookedFor(): void
    {
        $this->assertProblem(403, $this->change(self::UNKNOWN_ORDER, ['transition' => 'ship', 'version' => 2, 'note' => null], self::staff(['support'])));
        $this->assertProblem(403, $this->change(self::UNKNOWN_ORDER, ['transition' => 'cancel', 'version' => 2, 'note' => 'Brak'], self::staff(['warehouse'])));
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
        $this->assertProblem(404, $this->change($orderId, ['transition' => 'deliver', 'version' => 99, 'note' => null], self::staff(['admin'])));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function transitionsOutsideTheTable(): iterable
    {
        yield 'deliver a paid order' => ['deliver', null];
        yield 'return a paid order' => ['return', 'Zwrot'];
    }

    #[DataProvider('transitionsOutsideTheTable')]
    public function testATransitionTheStatusDoesNotAllowIsAConflictAndChangesNothing(string $transition, ?string $note): void
    {
        [$orderId, $variant] = $this->paidOrder();

        $this->assertConflict(self::CONFLICT_PREFIX.'order-transition-not-allowed', 'Tej zmiany nie można wykonać w obecnym statusie zamówienia.', $this->change($orderId, ['transition' => $transition, 'version' => 2, 'note' => $note], self::staff(['admin'])));

        self::assertSame(['paid', 2], $this->stored($orderId));
        self::assertSame([5, 2], OrderingFixture::stock($this->connection, $variant));
    }

    public function testCancellingANewOrderIsNotAllowed(): void
    {
        [$orderId] = $this->paidOrder();
        $this->connection->executeStatement("UPDATE ordering_order SET status = 'new', paid_at = NULL, payment_id = NULL WHERE id = ?", [$orderId]);

        $this->assertConflict(self::CONFLICT_PREFIX.'order-transition-not-allowed', 'Tej zmiany nie można wykonać w obecnym statusie zamówienia.', $this->change($orderId, ['transition' => 'cancel', 'version' => 2, 'note' => 'Brak'], self::staff(['support'])));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function staleVersions(): iterable
    {
        yield 'older' => [1];
        yield 'newer' => [3];
    }

    #[DataProvider('staleVersions')]
    public function testAStaleVersionIsAConflictAndChangesNothing(int $version): void
    {
        [$orderId, $variant] = $this->paidOrder();

        $this->assertConflict('about:blank', 'Zamówienie zostało w międzyczasie zmienione.', $this->change($orderId, ['transition' => 'ship', 'version' => $version, 'note' => null], self::staff(['admin'])));

        self::assertSame(['paid', 2], $this->stored($orderId));
        self::assertSame([5, 2], OrderingFixture::stock($this->connection, $variant));
    }

    public function testRepeatingASuccessfulChangeIsAConflict(): void
    {
        [$orderId] = $this->paidOrder();
        $body = ['transition' => 'ship', 'version' => 2, 'note' => null];
        $this->change($orderId, $body, self::staff(['warehouse']));
        self::assertResponseStatusCodeSame(200);

        $this->assertConflict('about:blank', 'Zamówienie zostało w międzyczasie zmienione.', $this->change($orderId, $body, self::staff(['warehouse'])));
        self::assertSame(['shipped', 3], $this->stored($orderId));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        $valid = ['transition' => 'ship', 'version' => 2, 'note' => null];

        yield 'no transition' => [['version' => 2, 'note' => null], 'transition'];
        yield 'no version' => [['transition' => 'ship', 'note' => null], 'version'];
        yield 'no note' => [['transition' => 'ship', 'version' => 2], 'note'];
        yield 'an unknown transition' => [['transition' => 'pay'] + $valid, 'transition'];
        yield 'a transition in upper case' => [['transition' => 'SHIP'] + $valid, 'transition'];
        yield 'a null transition' => [['transition' => null] + $valid, 'transition'];
        yield 'a transition that is no string' => [['transition' => 1] + $valid, 'transition'];
        yield 'a null version' => [['version' => null] + $valid, 'version'];
        yield 'version zero' => [['version' => 0] + $valid, 'version'];
        yield 'a negative version' => [['version' => -1] + $valid, 'version'];
        yield 'a version as a string' => [['version' => '2'] + $valid, 'version'];
        yield 'a fractional version' => [['version' => 2.5] + $valid, 'version'];
        yield 'a note that is no string' => [['note' => 12] + $valid, 'note'];
        yield 'a note over 500 characters' => [['note' => str_repeat('a', 501)] + $valid, 'note'];
        yield 'a note of two lines' => [['note' => "Kurier\nDPD"] + $valid, 'note'];
        yield 'a note with a control character' => [['note' => "Kurier\x07"] + $valid, 'note'];
        yield 'a cancellation without a note' => [['transition' => 'cancel'] + $valid, 'note'];
        yield 'a cancellation with a blank note' => [['transition' => 'cancel', 'note' => '  '] + $valid, 'note'];
        yield 'a return without a note' => [['transition' => 'return'] + $valid, 'note'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIsA422WithItsViolationAndChangesNothing(array $body, string $path): void
    {
        [$orderId] = $this->paidOrder();

        self::assertSame([$path], $this->violationPaths(422, $this->change($orderId, $body, self::staff(['admin']))));

        self::assertSame(['paid', 2], $this->stored($orderId));
    }

    public function testTheLongestNoteIsAccepted(): void
    {
        [$orderId] = $this->paidOrder();

        $order = $this->change($orderId, ['transition' => 'cancel', 'version' => 2, 'note' => ' '.str_repeat('ą', 500).' '], self::staff(['support']));

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($order['history']);
        self::assertSame(str_repeat('ą', 500), ((array) end($order['history']))['note']);
    }

    public function testATokenIsRequiredAndACustomerTokenIsNotAccepted(): void
    {
        [$orderId] = $this->paidOrder();
        $body = ['transition' => 'ship', 'version' => 2, 'note' => null];

        $this->assertUnauthorized($this->change($orderId, $body, null));
        $this->assertUnauthorized($this->change($orderId, $body, TestTokenFactory::customer()));
        self::assertSame(['paid', 2], $this->stored($orderId));
    }

    /**
     * @param array<mixed>|string $body
     *
     * @return array<mixed>
     */
    private function change(string $orderId, array|string $body, ?string $token, string $contentType = 'application/json'): array
    {
        return $this->postJson('/api/v1/admin/orders/'.$orderId.'/status-changes', $body, $token, $contentType);
    }

    /**
     * @return list<mixed> the status and the version of the stored order
     */
    private function stored(string $orderId): array
    {
        $row = $this->connection->fetchNumeric('SELECT status, version FROM ordering_order WHERE id = ?', [$orderId]);
        self::assertIsArray($row);

        return $row;
    }
}
