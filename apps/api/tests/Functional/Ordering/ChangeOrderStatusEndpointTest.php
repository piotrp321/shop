<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\Command\ChangeOrderStatus;
use App\Ordering\Domain\StaffTransition;
use App\Tests\Functional\Shared\RequiredKeyViolationsTrait;
use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\InputConformance\InputValues;
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
    use RequiredKeyViolationsTrait;

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
        yield from InputValues::uuidsOutsideTheVersions();
    }

    public function testAnotherNotationOfTheOrderIdChangesTheSameOrder(): void
    {
        [$orderId] = $this->paidOrder();

        foreach (InputValues::equivalentUuidForms($orderId) as [$notation]) {
            $this->change($notation, ['transition' => 'ship', 'version' => 2, 'note' => null], self::staff(['warehouse']));

            self::assertResponseStatusCodeSame(200);
            self::assertSame(['shipped', 3], $this->stored($orderId));
        }
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
        yield 'a note with U+0085' => [['note' => "Kurier\u{0085}DPD"] + $valid, 'note'];
        yield 'a note with U+2028' => [['note' => "Kurier\u{2028}DPD"] + $valid, 'note'];
        yield 'a note with a trailing U+2029' => [['note' => "Kurier DPD\u{2029}"] + $valid, 'note'];
        yield 'a cancellation with a note of U+2028' => [['transition' => 'cancel', 'note' => "\u{2028}"] + $valid, 'note'];
        yield 'a note over 500 characters once trimmed of non-breaking spaces' => [['note' => "\u{00A0}".str_repeat('a', 501)."\u{00A0}"] + $valid, 'note'];
        yield 'a cancellation with a note of non-breaking spaces' => [['transition' => 'cancel', 'note' => "\u{00A0}\u{00A0}"] + $valid, 'note'];
        yield 'a return with a note of Unicode white space' => [['transition' => 'return', 'note' => " \u{3000}\u{2003}"] + $valid, 'note'];
        yield 'a cancellation without a note' => [['transition' => 'cancel'] + $valid, 'note'];
        yield 'a cancellation with a blank note' => [['transition' => 'cancel', 'note' => '  '] + $valid, 'note'];
        yield 'a return without a note' => [['transition' => 'return'] + $valid, 'note'];
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function omittedBodyKeys(): iterable
    {
        return self::omittedKeys(['transition', 'version', 'note', 'trackingNumber']);
    }

    /**
     * @param list<string> $omitted
     */
    #[DataProvider('omittedBodyKeys')]
    public function testAnOmittedKeyIsOnePolishRequiredViolationAtThatKey(array $omitted): void
    {
        [$orderId] = $this->paidOrder();

        $problem = $this->change($orderId, self::bodyWithout(['transition' => 'ship', 'version' => 2, 'note' => null, 'trackingNumber' => null], $omitted), self::staff(['admin']), withTrackingNumber: false);

        $this->assertProblem(422, $problem);
        self::assertRequiredAt($omitted, $problem['violations'] ?? null);
        self::assertSame(['paid', 2], $this->stored($orderId));
    }

    public function testACancellationWithoutANoteKeyIsRequiredAtTheNoteOnly(): void
    {
        [$orderId] = $this->paidOrder();

        $problem = $this->change($orderId, ['transition' => 'cancel', 'version' => 2], self::staff(['admin']));

        $this->assertProblem(422, $problem);
        self::assertRequiredAt(['note'], $problem['violations'] ?? null);
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

    /**
     * @return iterable<string, array{string, string}> the note and the message at `note`
     */
    public static function notesWithTheirMessages(): iterable
    {
        foreach (InputValues::blankUnicodeTexts() as $name => [$text]) {
            yield 'only '.$name => [$text, 'Notatka jest wymagana.'];
        }
        yield 'U+2028' => ["Brak\u{2028}kuriera", 'Notatka zawiera niedozwolone znaki.'];
        yield 'U+2029' => ["Brak\u{2029}kuriera", 'Notatka zawiera niedozwolone znaki.'];
        yield 'U+0085' => ["Brak\u{0085}kuriera", 'Notatka zawiera niedozwolone znaki.'];
        yield 'U+2028 alone' => ["\u{2028}", 'Notatka zawiera niedozwolone znaki.'];
        yield 'over 500 characters once trimmed' => ["\u{00A0}".str_repeat('ą', 501)."\u{00A0}", 'Notatka może mieć najwyżej 500 znaków.'];
    }

    #[DataProvider('notesWithTheirMessages')]
    public function testACancellationWithAnUnacceptableNoteGivesOneMessageAtTheNote(string $note, string $message): void
    {
        [$orderId] = $this->paidOrder();

        $problem = $this->change($orderId, ['transition' => 'cancel', 'version' => 2, 'note' => $note], self::staff(['support']));

        self::assertSame(['note'], $this->violationPaths(422, $problem));
        self::assertIsArray($problem['violations']);
        self::assertCount(1, $problem['violations']);
        self::assertIsArray($problem['violations'][0]);
        self::assertSame($message, $problem['violations'][0]['title'] ?? null);
        self::assertSame(['paid', 2], $this->stored($orderId));
    }

    public function testANoteOfNonBreakingSpacesIsNoNoteForAShipment(): void
    {
        [$orderId] = $this->paidOrder();

        $order = $this->change($orderId, ['transition' => 'ship', 'version' => 2, 'note' => "\u{00A0}\u{00A0}"], self::staff(['warehouse']));

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($order['history']);
        self::assertNull(((array) end($order['history']))['note']);
    }

    public function testANoteIsTrimmedOfNonBreakingSpaces(): void
    {
        [$orderId] = $this->paidOrder();

        $order = $this->change($orderId, ['transition' => 'cancel', 'version' => 2, 'note' => "\u{00A0}Klient zrezygnował\u{00A0}"], self::staff(['support']));

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($order['history']);
        self::assertSame('Klient zrezygnował', ((array) end($order['history']))['note']);
    }

    public function testTheLongestNoteIsAccepted(): void
    {
        [$orderId] = $this->paidOrder();

        $order = $this->change($orderId, ['transition' => 'cancel', 'version' => 2, 'note' => ' '.str_repeat('ą', 500).' '], self::staff(['support']));

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($order['history']);
        self::assertSame(str_repeat('ą', 500), ((array) end($order['history']))['note']);
    }

    public function testAShipmentWithANumberKeepsItAndTheViewHasTheShipmentWithTheLink(): void
    {
        [$orderId] = $this->paidOrder();
        $this->connection->executeStatement("UPDATE ordering_order SET delivery_tracking_url_template = 'https://tracking.example.com/{numer}' WHERE id = ?", [$orderId]);

        $order = $this->change($orderId, ['transition' => 'ship', 'version' => 2, 'note' => null, 'trackingNumber' => " JJD0002-4455\u{00A0}"], self::staff(['warehouse']));

        self::assertResponseStatusCodeSame(200);
        self::assertMatchesSchema('BackofficeOrder', $order);
        self::assertSame(['carrier' => 'Kurier testowy bez kosztu', 'trackingNumber' => 'JJD0002-4455', 'trackingUrl' => 'https://tracking.example.com/JJD0002-4455'], $order['shipment']);
        self::assertSame(['methodName' => 'Kurier testowy bez kosztu', 'gross' => 0], $order['delivery']);
        self::assertSame('JJD0002-4455', $this->connection->fetchOne('SELECT tracking_number FROM ordering_order WHERE id = ?', [$orderId]));
    }

    public function testAShipmentWithoutANumberHasNoShipmentButKeepsTheDelivery(): void
    {
        [$orderId] = $this->paidOrder();

        $order = $this->change($orderId, ['transition' => 'ship', 'version' => 2, 'note' => null, 'trackingNumber' => null], self::staff(['warehouse']));

        self::assertResponseStatusCodeSame(200);
        self::assertNull($order['shipment']);
        self::assertSame(['methodName' => 'Kurier testowy bez kosztu', 'gross' => 0], $order['delivery']);
        self::assertNull($this->connection->fetchOne('SELECT tracking_number FROM ordering_order WHERE id = ?', [$orderId]) ?: null);
    }

    /**
     * @return iterable<string, array{string, string}> the transition and its status before it
     */
    public static function everyTransition(): iterable
    {
        yield 'ship' => ['ship', 'paid'];
        yield 'deliver' => ['deliver', 'shipped'];
        yield 'cancel' => ['cancel', 'paid'];
        yield 'return' => ['return', 'shipped'];
    }

    /**
     * @return iterable<string, array{string|null, string, string}> the blank text, the transition and its status before it
     */
    public static function blankNumbersAtEveryTransition(): iterable
    {
        $blank = ['null' => null, 'empty' => '', 'spaces' => '   '];
        foreach (InputValues::blankUnicodeTexts() as $name => [$text]) {
            $blank['only '.$name] = $text;
        }
        foreach ($blank as $name => $text) {
            foreach (self::everyTransition() as $transition => [$value, $before]) {
                yield $name.', '.$transition => [$text, $value, $before];
            }
        }
    }

    #[DataProvider('blankNumbersAtEveryTransition')]
    public function testNullAndNothingButWhiteSpaceAreNoNumberAtEveryTransition(?string $number, string $transition, string $before): void
    {
        [$orderId, $version] = $this->orderBefore($before);

        $order = $this->change($orderId, ['transition' => $transition, 'version' => $version, 'note' => \in_array($transition, ['cancel', 'return'], true) ? 'Notatka' : null, 'trackingNumber' => $number], self::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        self::assertNull($order['shipment']);
        self::assertNull($this->connection->fetchOne('SELECT tracking_number FROM ordering_order WHERE id = ?', [$orderId]) ?: null);
    }

    /**
     * @return iterable<string, array{mixed, string}> the value of a shipment and the message at `trackingNumber`
     */
    public static function refusedForAShipment(): iterable
    {
        $format = 'Numer przesyłki może mieć 1–40 znaków: litery bez polskich znaków, cyfry i myślniki.';
        yield 'a Polish letter' => ['PRZESYŁKA-1', $format];
        yield 'a space inside' => ['123 456', $format];
        yield '41 characters' => [str_repeat('a', 41), $format];
        yield 'a NUL' => ["123\0456", $format];
        yield 'U+2028 inside' => ["123\u{2028}456", $format];
        yield 'a NBSP inside' => ["123\u{00A0}456", $format];
        yield 'a number' => [123, 'Numer przesyłki musi być tekstem.'];
        yield 'false' => [false, 'Numer przesyłki musi być tekstem.'];
        yield 'a list' => [[], 'Numer przesyłki musi być tekstem.'];
    }

    #[DataProvider('refusedForAShipment')]
    public function testAShipmentRefusesANumberOfAWrongShapeOrTypeAtTheFieldAndChangesNothing(mixed $value, string $message): void
    {
        [$orderId, $variant] = $this->paidOrder();

        $problem = $this->change($orderId, ['transition' => 'ship', 'version' => 2, 'note' => null, 'trackingNumber' => $value], self::staff(['warehouse']));

        self::assertSame(['trackingNumber'], $this->violationPaths(422, $problem));
        self::assertIsArray($problem['violations']);
        self::assertCount(1, $problem['violations']);
        self::assertIsArray($problem['violations'][0]);
        self::assertSame($message, $problem['violations'][0]['title'] ?? null);
        self::assertSame(['paid', 2], $this->stored($orderId));
        self::assertSame([5, 2], OrderingFixture::stock($this->connection, $variant), 'the reservation stays');
    }

    /**
     * @return iterable<string, array{string, string, mixed}>
     */
    public static function numbersOfOtherTransitions(): iterable
    {
        $values = ['a good number' => 'JJD-1', 'a bad format' => 'PRZESYŁKA 1', 'too long' => str_repeat('a', 41), 'a NUL' => "1\0", 'a number' => 123, 'true' => true, 'false' => false, 'zero' => 0, 'a list' => []];
        foreach (self::everyTransition() as $transition => [$value, $before]) {
            if ('ship' === $value) {
                continue;
            }
            foreach ($values as $name => $number) {
                yield $name.', '.$transition => [$value, $before, $number];
            }
        }
    }

    #[DataProvider('numbersOfOtherTransitions')]
    public function testOtherTransitionsTakeNoNumberOfAnyShapeAndChangeNothing(string $transition, string $before, mixed $number): void
    {
        [$orderId, $version] = $this->orderBefore($before);

        $problem = $this->change($orderId, ['transition' => $transition, 'version' => $version, 'note' => 'deliver' === $transition ? null : 'Notatka', 'trackingNumber' => $number], self::staff(['admin']));

        self::assertSame(['trackingNumber'], $this->violationPaths(422, $problem));
        self::assertIsArray($problem['violations']);
        self::assertCount(1, $problem['violations']);
        self::assertIsArray($problem['violations'][0]);
        self::assertSame('Numer przesyłki można podać tylko przy wysyłce.', $problem['violations'][0]['title'] ?? null, 'before the message of the format');
        self::assertSame([$before, $version], $this->stored($orderId));
    }

    /**
     * An order of a guest in the status before a transition (paid: version 2; shipped: version 3).
     *
     * @return array{string, int} the order and its version
     */
    private function orderBefore(string $status): array
    {
        [$orderId] = $this->paidOrder();
        if ('shipped' === $status) {
            $this->dispatch(new ChangeOrderStatus($orderId, StaffTransition::Ship, 2, null, 'kc-staff-1', 'marek'));
            $this->entityManager->clear();
        }

        return [$orderId, 'shipped' === $status ? 3 : 2];
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
    private function change(string $orderId, array|string $body, ?string $token, string $contentType = 'application/json', bool $withTrackingNumber = true): array
    {
        // The key is required (W8): the bodies of the tests that are not about it say "no number" unless they set one.
        if ($withTrackingNumber && \is_array($body)) {
            $body += ['trackingNumber' => null];
        }

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
