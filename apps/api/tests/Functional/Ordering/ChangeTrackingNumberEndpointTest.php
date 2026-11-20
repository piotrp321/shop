<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\Command\ChangeOrderStatus;
use App\Ordering\Domain\StaffTransition;
use App\Tests\Functional\Shared\RequiredKeyViolationsTrait;
use App\Tests\Support\InputConformance\InputValues;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * PUT /api/v1/admin/orders/{orderId}/tracking-number on PostgreSQL (shop_test, rolled back after each test)
 * (docs/features/delivery/spec.md, W8a, P18): the number of a shipped or delivered order is added, replaced and removed
 * by an administrator and the warehouse, with the version of the order; the rule of the roles of the voter comes
 * before the body, then 404 and 409; nothing else changes (no event, no email, no entry of the history).
 */
final class ChangeTrackingNumberEndpointTest extends WebTestCase
{
    use BackofficeOrderEndpointTestTrait;
    use RequiredKeyViolationsTrait;

    private const string UNKNOWN_ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000ff';
    private const string CONFLICT_PREFIX = 'tag:piotrp321.github.io,2026:shop/problems/';
    private const string NOT_CHANGEABLE = 'Numer przesyłki można zmienić tylko w zamówieniu wysłanym albo dostarczonym.';
    private const string FORMAT = 'Numer przesyłki może mieć 1–40 znaków: litery bez polskich znaków, cyfry i myślniki.';

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function shippers(): iterable
    {
        yield 'admin' => [['admin']];
        yield 'warehouse' => [['warehouse']];
        yield 'warehouse and marketing' => [['warehouse', 'marketing']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('shippers')]
    public function testItAddsReplacesAndRemovesTheNumberOfAShippedOrder(array $roles): void
    {
        $orderId = $this->shippedOrder();
        $version = 3;

        $added = $this->change($orderId, ['trackingNumber' => ' JJD0002-4455 ', 'version' => $version], self::staff($roles));
        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
        self::assertMatchesSchema('BackofficeOrder', $added);
        self::assertSame(['shipped', $version + 1, true], [$added['status'], $added['version'], $added['canChangeTrackingNumber']]);
        self::assertSame(['carrier' => 'Kurier testowy bez kosztu', 'trackingNumber' => 'JJD0002-4455', 'trackingUrl' => null], $added['shipment']);
        self::assertSame('JJD0002-4455', $this->storedNumber($orderId));

        $replaced = $this->change($orderId, ['trackingNumber' => 'ŹLE-1', 'version' => $version + 1], self::staff($roles));
        self::assertSame(['trackingNumber'], $this->violationPaths(422, $replaced), 'a wrong shape is refused');
        $replaced = $this->change($orderId, ['trackingNumber' => 'XYZ-9', 'version' => $version + 1], self::staff($roles));
        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($replaced['shipment']);
        self::assertSame('XYZ-9', $replaced['shipment']['trackingNumber']);
        self::assertSame($version + 2, $replaced['version']);

        $removed = $this->change($orderId, ['trackingNumber' => null, 'version' => $version + 2], self::staff($roles));
        self::assertResponseStatusCodeSame(200);
        self::assertNull($removed['shipment']);
        self::assertNull($this->storedNumber($orderId));
        self::assertSame($version + 3, $removed['version']);
    }

    public function testTheLinkOfTheShipmentComesFromTheCopyOfTheTemplate(): void
    {
        $orderId = $this->shippedOrder();
        $this->connection->executeStatement("UPDATE ordering_order SET delivery_tracking_url_template = 'https://tracking.example.com/t?n={numer}' WHERE id = ?", [$orderId]);

        $order = $this->change($orderId, ['trackingNumber' => 'AB-12', 'version' => 3], self::staff(['warehouse']));

        self::assertSame(['carrier' => 'Kurier testowy bez kosztu', 'trackingNumber' => 'AB-12', 'trackingUrl' => 'https://tracking.example.com/t?n=AB-12'], $order['shipment']);
    }

    public function testItChangesTheNumberOfADeliveredOrderToo(): void
    {
        $orderId = $this->shippedOrder();
        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Deliver, 3, null, 'kc-staff-1', 'marek'));

        $order = $this->change($orderId, ['trackingNumber' => 'AB-12', 'version' => 4], self::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['delivered', 5, true], [$order['status'], $order['version'], $order['canChangeTrackingNumber']]);
        self::assertSame('AB-12', $this->storedNumber($orderId));
    }

    public function testItNeitherSendsAnEmailNorRecordsAnEventOrAHistoryEntry(): void
    {
        $orderId = $this->shippedOrder();
        $messages = $this->scalarInt('SELECT count(*) FROM messenger_messages');
        $history = $this->scalarInt('SELECT count(*) FROM ordering_order_status_change WHERE order_id = ?', [$orderId]);

        $this->change($orderId, ['trackingNumber' => 'AB-12', 'version' => 3], self::staff(['warehouse']));
        self::assertResponseStatusCodeSame(200);
        $this->change($orderId, ['trackingNumber' => null, 'version' => 4], self::staff(['warehouse']));
        self::assertResponseStatusCodeSame(200);

        self::assertSame($messages, $this->scalarInt('SELECT count(*) FROM messenger_messages'), 'no event, no message of any queue');
        self::assertSame($history, $this->scalarInt('SELECT count(*) FROM ordering_order_status_change WHERE order_id = ?', [$orderId]), 'no entry of the history');
    }

    /**
     * @return iterable<string, array{mixed}> values that remove the number
     */
    public static function removingValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        foreach (InputValues::blankUnicodeTexts() as $name => [$text]) {
            yield 'only '.$name => [$text];
        }
    }

    #[DataProvider('removingValues')]
    public function testNullAndNothingButWhiteSpaceRemoveTheNumber(mixed $value): void
    {
        $orderId = $this->shippedOrder('AB-12');

        $order = $this->change($orderId, ['trackingNumber' => $value, 'version' => 3], self::staff(['warehouse']));

        self::assertResponseStatusCodeSame(200);
        self::assertNull($order['shipment']);
        self::assertNull($this->storedNumber($orderId));
    }

    /**
     * @return iterable<string, array{mixed, string}> the value and the message at `trackingNumber`
     */
    public static function refusedNumbers(): iterable
    {
        yield 'a Polish letter' => ['PRZESYŁKA-1', self::FORMAT];
        yield 'a space inside' => ['12 34', self::FORMAT];
        yield '41 characters' => [str_repeat('a', 41), self::FORMAT];
        yield 'a NUL' => ["12\0".'34', self::FORMAT];
        yield 'U+2028 inside' => ["12\u{2028}34", self::FORMAT];
        yield 'a NBSP inside' => ["12\u{00A0}34", self::FORMAT];
        yield 'a slash' => ['12/34', self::FORMAT];
        yield 'a number' => [1234, 'Numer przesyłki musi być tekstem.'];
        yield 'true' => [true, 'Numer przesyłki musi być tekstem.'];
        yield 'a list' => [['a'], 'Numer przesyłki musi być tekstem.'];
    }

    #[DataProvider('refusedNumbers')]
    public function testANumberOfAWrongShapeOrTypeIsA422AtTheFieldAndChangesNothing(mixed $value, string $message): void
    {
        $orderId = $this->shippedOrder('AB-12');

        $problem = $this->change($orderId, ['trackingNumber' => $value, 'version' => 3], self::staff(['admin']));

        self::assertSame(['trackingNumber'], $this->violationPaths(422, $problem));
        self::assertIsArray($problem['violations']);
        self::assertCount(1, $problem['violations']);
        self::assertIsArray($problem['violations'][0]);
        self::assertSame($message, $problem['violations'][0]['title'] ?? null);
        self::assertSame(['AB-12', 3], [$this->storedNumber($orderId), $this->storedVersion($orderId)]);
    }

    public function testTheLongestNumberIsAccepted(): void
    {
        $orderId = $this->shippedOrder();

        $order = $this->change($orderId, ['trackingNumber' => str_repeat('Z9-', 13).'Z', 'version' => 3], self::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($order['shipment']);
        self::assertIsString($order['shipment']['trackingNumber']);
        self::assertSame(40, \strlen($order['shipment']['trackingNumber']));
    }

    /**
     * @return iterable<string, array{string}> the state an order is brought to, which allows no change of the number
     */
    public static function statesOfNoChange(): iterable
    {
        yield 'new' => ['new'];
        yield 'paid' => ['paid'];
        yield 'cancelled' => ['cancelled'];
        yield 'returned' => ['returned'];
    }

    #[DataProvider('statesOfNoChange')]
    public function testAnOrderThatIsNeitherShippedNorDeliveredIsAConflictAndChangesNothing(string $state): void
    {
        [$orderId, $version] = $this->orderIn($state);

        $this->assertConflict(self::CONFLICT_PREFIX.'tracking-number-not-changeable', self::NOT_CHANGEABLE, $this->change($orderId, ['trackingNumber' => 'AB-12', 'version' => $version], self::staff(['admin'])));

        self::assertSame([null, $version], [$this->storedNumber($orderId), $this->storedVersion($orderId)]);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function others(): iterable
    {
        yield 'support' => [['support']];
        yield 'marketing' => [['marketing']];
        yield 'support and marketing' => [['support', 'marketing']];
        yield 'no staff role' => [[]];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('others')]
    public function testOnlyAdminAndTheWarehouseChangeTheNumberAndTheRoleIsCheckedFirst(array $roles): void
    {
        $orderId = $this->shippedOrder('AB-12');

        $this->assertProblem(403, $this->change($orderId, ['trackingNumber' => 'XY-1', 'version' => 3], self::staff($roles)));
        $this->assertProblem(403, $this->change($orderId, '{not json', self::staff($roles)));
        $this->assertProblem(403, $this->change($orderId, ['trackingNumber' => 'Źle', 'version' => 'x'], self::staff($roles)));
        $this->assertProblem(403, $this->change($orderId, 'trackingNumber=XY-1', self::staff($roles), 'text/plain'));
        $this->assertProblem(403, $this->change(self::UNKNOWN_ORDER, ['trackingNumber' => 'XY-1', 'version' => 3], self::staff($roles)));

        self::assertSame(['AB-12', 3], [$this->storedNumber($orderId), $this->storedVersion($orderId)]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownOrders(): iterable
    {
        yield 'an unknown order' => [self::UNKNOWN_ORDER];
        yield 'an id that is no UUID' => ['R-100001'];
        yield from InputValues::uuidsOutsideTheVersions();
    }

    #[DataProvider('unknownOrders')]
    public function testAnUnknownOrderIsA404BeforeAnyConflict(string $orderId): void
    {
        $this->assertProblem(404, $this->change($orderId, ['trackingNumber' => 'AB-12', 'version' => 99], self::staff(['admin'])));
    }

    public function testAnotherNotationOfTheOrderIdChangesTheSameOrder(): void
    {
        $orderId = $this->shippedOrder();

        foreach (InputValues::equivalentUuidForms($orderId) as [$notation]) {
            $order = $this->change($notation, ['trackingNumber' => 'AB-12', 'version' => $this->storedVersion($orderId)], self::staff(['warehouse']));

            self::assertResponseStatusCodeSame(200);
            self::assertSame($orderId, $order['orderId']);
            self::assertSame('AB-12', $this->storedNumber($orderId));
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function staleVersions(): iterable
    {
        yield 'older' => [2];
        yield 'newer' => [4];
    }

    #[DataProvider('staleVersions')]
    public function testAStaleVersionIsAConflictAndChangesNothing(int $version): void
    {
        $orderId = $this->shippedOrder('AB-12');

        $this->assertConflict('about:blank', 'Zamówienie zostało w międzyczasie zmienione.', $this->change($orderId, ['trackingNumber' => 'XY-1', 'version' => $version], self::staff(['admin'])));

        self::assertSame(['AB-12', 3], [$this->storedNumber($orderId), $this->storedVersion($orderId)]);
    }

    public function testRepeatingASuccessfulChangeWithTheSameVersionIsAConflict(): void
    {
        $orderId = $this->shippedOrder();
        $body = ['trackingNumber' => 'AB-12', 'version' => 3];
        $this->change($orderId, $body, self::staff(['warehouse']));
        self::assertResponseStatusCodeSame(200);

        $this->assertConflict('about:blank', 'Zamówienie zostało w międzyczasie zmienione.', $this->change($orderId, $body, self::staff(['warehouse'])));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        $valid = ['trackingNumber' => 'AB-12', 'version' => 3];

        yield 'a null version' => [['version' => null] + $valid, 'version'];
        yield 'version zero' => [['version' => 0] + $valid, 'version'];
        yield 'a negative version' => [['version' => -1] + $valid, 'version'];
        yield 'a version as a string' => [['version' => '3'] + $valid, 'version'];
        yield 'a fractional version' => [['version' => 3.5] + $valid, 'version'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testAnInvalidVersionIsA422AtTheVersionAndChangesNothing(array $body, string $path): void
    {
        $orderId = $this->shippedOrder('JJ-1');

        self::assertSame([$path], $this->violationPaths(422, $this->change($orderId, $body, self::staff(['admin']))));

        self::assertSame(['JJ-1', 3], [$this->storedNumber($orderId), $this->storedVersion($orderId)]);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function omittedBodyKeys(): iterable
    {
        return self::omittedKeys(['trackingNumber', 'version']);
    }

    /**
     * @param list<string> $omitted
     */
    #[DataProvider('omittedBodyKeys')]
    public function testAnOmittedKeyIsOnePolishRequiredViolationAtThatKey(array $omitted): void
    {
        $orderId = $this->shippedOrder('JJ-1');

        $problem = $this->change($orderId, self::bodyWithout(['trackingNumber' => 'AB-12', 'version' => 3], $omitted), self::staff(['admin']));

        $this->assertProblem(422, $problem);
        self::assertRequiredAt($omitted, $problem['violations'] ?? null);
        self::assertSame(['JJ-1', 3], [$this->storedNumber($orderId), $this->storedVersion($orderId)]);
    }

    public function testABodyThatIsNoJsonIsA400AndAnotherMediaTypeA415(): void
    {
        $orderId = $this->shippedOrder();

        $this->assertProblem(400, $this->change($orderId, '{"trackingNumber": "AB-1"', self::staff(['admin'])));
        $this->assertProblem(415, $this->change($orderId, 'trackingNumber=AB-1', self::staff(['admin']), 'text/plain'));
    }

    public function testATokenIsRequiredAndACustomerTokenIsNotAccepted(): void
    {
        $orderId = $this->shippedOrder();
        $body = ['trackingNumber' => 'AB-12', 'version' => 3];

        $this->assertUnauthorized($this->change($orderId, $body, null));
        $this->assertUnauthorized($this->change($orderId, $body, TestTokenFactory::customer()));
        self::assertNull($this->storedNumber($orderId));
    }

    /**
     * A paid order shipped by the warehouse, with the number if given: version 3, history placed, paid and shipped.
     */
    private function shippedOrder(?string $number = null): string
    {
        [$orderId] = $this->paidOrder();
        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Ship, 2, null, 'kc-staff-1', 'marek', $number));
        $this->entityManager->clear();

        return $orderId;
    }

    /**
     * @return array{string, int} the order and its version
     */
    private function orderIn(string $state): array
    {
        [$orderId] = $this->paidOrder();
        match ($state) {
            'new' => $this->connection->executeStatement("UPDATE ordering_order SET status = 'new', paid_at = NULL, payment_id = NULL WHERE id = ?", [$orderId]),
            'cancelled' => $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Cancel, 2, 'Brak towaru', 'kc-staff-1', 'anna')),
            'returned' => $this->returned($orderId),
            default => null,
        };
        $this->entityManager->clear();

        return [$orderId, $this->storedVersion($orderId)];
    }

    private function returned(string $orderId): void
    {
        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Ship, 2, null, 'kc-staff-1', 'marek'));
        $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Return, 3, 'Zwrot', 'kc-staff-1', 'anna'));
    }

    private function storedNumber(string $orderId): ?string
    {
        $number = $this->connection->fetchOne('SELECT tracking_number FROM ordering_order WHERE id = ?', [$orderId]);

        return \is_string($number) ? $number : null;
    }

    private function storedVersion(string $orderId): int
    {
        return $this->scalarInt('SELECT version FROM ordering_order WHERE id = ?', [$orderId]);
    }

    /**
     * @param list<string> $parameters
     */
    private function scalarInt(string $sql, array $parameters = []): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsInt($value);

        return $value;
    }

    /**
     * @param array<mixed>|string $body
     *
     * @return array<mixed>
     */
    private function change(string $orderId, array|string $body, ?string $token, string $contentType = 'application/json'): array
    {
        return $this->putJson('/api/v1/admin/orders/'.$orderId.'/tracking-number', $body, $token, $contentType);
    }
}
