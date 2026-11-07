<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Ordering\Application\Query\BackofficeOrderCursor;
use App\Tests\Integration\Ordering\BackofficeOrderFixture;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/v1/me/orders and GET /api/v1/me/orders/{orderId} on PostgreSQL (shop_test, rolled back after each test; the
 * orders of the test are the only ones in its transaction).
 */
final class CustomerOrderHistoryEndpointTest extends WebTestCase
{
    private const string PATH = '/api/v1/me/orders';
    private const string OTHER_CUSTOMER = 'another-customer';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        BackofficeOrderFixture::noOrders($this->connection);
    }

    public function testACustomerSeesOnlyTheirOrdersNewestFirstWithTheFieldsOfTheList(): void
    {
        $older = $this->order('paid', '-2 days', 'R-100001', quantities: [2, 3]);
        $newer = $this->order('new', '-1 minute', 'R-100002');
        $this->order('paid', '-3 hours', 'R-100003', customerId: self::OTHER_CUSTOMER);
        BackofficeOrderFixture::order($this->connection, 'paid', new \DateTimeImmutable('-4 hours')->format('Y-m-d H:i:sP'), 'R-100004');

        $list = $this->get(self::PATH, TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
        self::assertMatchesSchema('CustomerOrderList', $list);
        self::assertNull($list['nextCursor']);
        self::assertIsArray($list['items']);
        self::assertSame([$newer, $older], array_column($list['items'], 'orderId'));
        [$new, $paid] = $list['items'];
        self::assertIsArray($new);
        self::assertIsArray($paid);
        self::assertSame(['R-100002', 'new', 1_000, 'PLN', 1, false], [$new['number'], $new['status'], $new['totalGross'], $new['currency'], $new['itemCount'], $new['paymentDeadlinePassed']]);
        self::assertSame(['R-100001', 'paid', 5_000, 'PLN', 5, false], [$paid['number'], $paid['status'], $paid['totalGross'], $paid['currency'], $paid['itemCount'], $paid['paymentDeadlinePassed']]);
        self::assertUtc($new['placedAt']);
        self::assertUtc($new['paymentDeadline']);
        self::assertStringNotContainsString('R-100003', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('R-100004', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('jan@example.com', (string) $this->client->getResponse()->getContent(), 'no contact data in the list');
    }

    public function testThePaymentDeadlinePassedComesFromTheApiForANewOrderOnly(): void
    {
        $late = $this->order('new', '-1 hour', 'R-100001');
        $paidLate = $this->order('paid', '-2 hours', 'R-100002');
        $cancelled = $this->order('cancelled', '-3 hours', 'R-100003');

        $list = $this->get(self::PATH, TestTokenFactory::customer());

        $passed = [];
        self::assertIsArray($list['items']);
        foreach ($list['items'] as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['orderId']);
            $passed[$item['orderId']] = $item['paymentDeadlinePassed'];
        }
        self::assertSame([$late => true, $paidLate => false, $cancelled => false], $passed);
    }

    public function testAnEmptyHistory(): void
    {
        $this->order('paid', '-1 day', 'R-100001', customerId: self::OTHER_CUSTOMER);

        self::assertSame(['items' => [], 'nextCursor' => null], $this->get(self::PATH, TestTokenFactory::customer()));
        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
    }

    public function testTenOrdersAPageByDefaultAndTheCursorPagesWithoutDuplicates(): void
    {
        $expected = [];
        for ($n = 0; $n < 25; ++$n) {
            // Several orders share a moment, so the id breaks the ties.
            array_unshift($expected, $this->order('paid', '-'.(30 - intdiv($n, 3)).' hours', 'R-'.(100_001 + $n)));
        }
        $first = $this->get(self::PATH, TestTokenFactory::customer());
        self::assertIsArray($first['items']);
        self::assertCount(10, $first['items'], 'ten by default');
        self::assertIsString($first['nextCursor']);

        $seen = [];
        $path = self::PATH.'?perPage=7';
        for ($page = 0; $page < 6; ++$page) {
            $list = $this->get($path, TestTokenFactory::customer());
            self::assertResponseStatusCodeSame(200);
            self::assertIsArray($list['items']);
            $seen = [...$seen, ...self::ids($list['items'])];
            if (null === $list['nextCursor']) {
                break;
            }
            self::assertIsString($list['nextCursor']);
            $path = self::PATH.'?perPage=7&after='.$list['nextCursor'];
        }

        self::assertCount(25, array_unique($seen), 'every order once');
        self::assertEqualsCanonicalizing($expected, $seen);
        $all = $this->get(self::PATH.'?perPage=50', TestTokenFactory::customer());
        self::assertIsArray($all['items']);
        self::assertSame(self::ids($all['items']), $seen, 'the pages together are the one page');
        self::assertNull($all['nextCursor']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badParameters(): iterable
    {
        yield 'zero per page' => ['perPage=0', 'perPage'];
        yield 'per page over 50' => ['perPage=51', 'perPage'];
        yield 'per page not a number' => ['perPage=many', 'perPage'];
        yield 'malformed cursor' => ['after=garbage!', 'after'];
        yield 'cursor of another shape' => ['after=aGVsbG8', 'after'];
        yield 'empty cursor' => ['after=', 'after'];
        yield 'cursor too long' => ['after='.str_repeat('a', 2049), 'after'];
        yield 'cursor as an array' => ['after[]=x', 'after'];
        yield 'cursor of the pending refunds of the panel' => ['after='.self::refundCursor(), 'after'];
    }

    #[DataProvider('badParameters')]
    public function testBadParametersAreA400(string $query, string $path): void
    {
        $this->order('paid', '-1 day', 'R-100001');

        $problem = $this->get(self::PATH.'?'.$query, TestTokenFactory::customer());

        $this->assertProblem(400, $problem);
        self::assertIsArray($problem['violations'] ?? null);
        $paths = [];
        foreach ($problem['violations'] as $violation) {
            self::assertIsArray($violation);
            self::assertIsString($violation['propertyPath']);
            $paths[] = $violation['propertyPath'];
        }
        self::assertSame([$path], array_values(array_unique($paths)));
        self::assertStringNotContainsString('garbage', (string) $this->client->getResponse()->getContent());
    }

    public function testTheDetailHasTheLinesTotalsAddressAndStatusDatesAndNoInternals(): void
    {
        $orderId = $this->order('returned', '-5 days', 'R-100001', quantities: [2, 1], refundDueAt: '+9 days');

        $order = $this->get(self::PATH.'/'.$orderId, TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
        self::assertMatchesSchema('CustomerOrder', $order);
        self::assertSame([$orderId, 'R-100001', 'returned'], [$order['orderId'], $order['number'], $order['status']]);
        self::assertSame(['street' => 'Długa 1', 'postalCode' => '00-950', 'city' => 'Warszawa', 'country' => 'PL'], $order['shippingAddress']);
        self::assertIsArray($order['lines']);
        self::assertCount(2, $order['lines']);
        self::assertSame(['Kubek', '', 2, 1_000, 2_000], $this->lineValues($order['lines'][0]));
        self::assertSame(['net' => 3_000, 'vat' => 0, 'gross' => 3_000, 'currency' => 'PLN'], $order['totals']);
        foreach (['placedAt', 'paymentDeadline', 'paidAt', 'shippedAt', 'returnedAt'] as $moment) {
            self::assertUtc($order[$moment], $moment);
        }
        self::assertNull($order['deliveredAt']);
        self::assertNull($order['cancelledAt']);
        self::assertFalse($order['paymentDeadlinePassed']);
        foreach (['history', 'refund', 'version', 'paymentId', 'email', 'allowedTransitions', 'cancellationReason', 'accessToken'] as $hidden) {
            self::assertArrayNotHasKey($hidden, $order);
        }
    }

    public function testTheDetailOfAnUnpaidOrderTellsFromTheApiWhetherItsDeadlinePassed(): void
    {
        $waiting = $this->order('new', '-1 minute', 'R-100001');
        $late = $this->order('new', '-1 hour', 'R-100002');

        self::assertFalse($this->get(self::PATH.'/'.$waiting, TestTokenFactory::customer())['paymentDeadlinePassed']);
        self::assertTrue($this->get(self::PATH.'/'.$late, TestTokenFactory::customer())['paymentDeadlinePassed']);
    }

    public function testACancelledOrderHasItsCancellationMoment(): void
    {
        $orderId = $this->order('cancelled_by_staff', '-2 days', 'R-100001');

        $order = $this->get(self::PATH.'/'.$orderId, TestTokenFactory::customer());

        self::assertSame('cancelled', $order['status']);
        self::assertUtc($order['cancelledAt']);
        self::assertArrayNotHasKey('refund', $order);
    }

    public function testAnotherCustomersOrderAGuestOrderAnUnknownOneAndANonUuidAreTheSame404(): void
    {
        $others = $this->order('paid', '-1 day', 'R-100001', customerId: self::OTHER_CUSTOMER);
        $guest = BackofficeOrderFixture::order($this->connection, 'paid', new \DateTimeImmutable('-1 day')->format('Y-m-d H:i:sP'), 'R-100002');

        $bodies = [];
        foreach ([$others, $guest, '0192f0c4-7c1a-7c4e-9a6e-0000000000ff', 'not-a-uuid', '0192F0C4-7C1A-7C4E-9A6E-0000000000FF', '%00'] as $id) {
            $problem = $this->get(self::PATH.'/'.$id, TestTokenFactory::customer());
            $this->assertProblem(404, $problem);
            $bodies[] = $problem;
        }

        self::assertCount(1, array_unique(array_map(static fn (array $problem): string => json_encode($problem, \JSON_THROW_ON_ERROR), $bodies)), 'one text for every case');
    }

    public function testAnOrderOfTheCustomerIsFoundByAnUpperCaseIdToo(): void
    {
        $orderId = $this->order('paid', '-1 day', 'R-100001', orderId: '0192f0c4-7c1a-7c4e-9a6e-0000000000ab');

        $this->get(self::PATH.'/'.strtoupper($orderId), TestTokenFactory::customer());

        self::assertResponseStatusCodeSame(200);
    }

    public function testATokenIsRequiredAndABackofficeTokenIsNotAccepted(): void
    {
        $orderId = $this->order('paid', '-1 day', 'R-100001');

        foreach ([self::PATH, self::PATH.'/'.$orderId] as $path) {
            foreach ([null, TestTokenFactory::staff(['admin']), 'not-a-token'] as $token) {
                $problem = $this->get($path, $token);
                $this->assertProblem(401, $problem);
                self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
                self::assertStringNotContainsString('R-100001', (string) $this->client->getResponse()->getContent());
            }
        }
    }

    /**
     * @param array<mixed> $items
     *
     * @return list<string>
     */
    private static function ids(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['orderId']);
            $ids[] = $item['orderId'];
        }

        return $ids;
    }

    private static function refundCursor(): string
    {
        $moment = new \DateTimeImmutable('2026-11-05 10:00:00+00:00');

        return BackofficeOrderCursor::after(new \App\Ordering\Application\Port\ListedOrder('0192f0c4-7c1a-7c4e-9a6e-000000000001', 'R-100001', $moment, 'returned', 'jan@example.com', 'Jan', 1_000, 'PLN', 1, 1_000, $moment, null), true)->encode();
    }

    /**
     * @return array<mixed>
     */
    private function get(string $path, ?string $token): array
    {
        $this->client->request('GET', $path, server: null === $token ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = '' === $content ? [] : json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * An order of the customer of the token (or of $customerId), placed relative to now.
     *
     * @param 'new'|'paid'|'shipped'|'delivered'|'cancelled'|'cancelled_by_staff'|'returned' $status
     * @param list<int>                                                                      $quantities
     */
    private function order(string $status, string $placedAt, string $number, array $quantities = [1], ?string $refundDueAt = null, ?string $orderId = null, string $customerId = TestTokenFactory::CUSTOMER_ID): string
    {
        $moment = static fn (?string $relative): ?string => null === $relative ? null : new \DateTimeImmutable($relative)->format('Y-m-d H:i:sP');

        return BackofficeOrderFixture::order($this->connection, $status, (string) $moment($placedAt), $number, 'jan@example.com', $quantities, $moment($refundDueAt), null, $orderId, $customerId);
    }

    /**
     * @return list<mixed>
     */
    private function lineValues(mixed $line): array
    {
        self::assertIsArray($line);

        return [$line['productName'], $line['variantLabel'], $line['quantity'], $line['unitGross'], $line['lineGross']];
    }

    /**
     * @param array<mixed> $problem
     */
    private function assertProblem(int $status, array $problem): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame($status, $problem['status'] ?? null);
        $this->assertPrivateAndNotStored();
    }

    private function assertPrivateAndNotStored(): void
    {
        $directives = array_map(trim(...), explode(',', (string) $this->client->getResponse()->headers->get('Cache-Control')));
        self::assertEqualsCanonicalizing(['private', 'no-store'], $directives);
    }

    /**
     * The keys of the body are the properties of the schema in openapi.json, and so on for every nested object and
     * array of objects that refers to a schema.
     *
     * @param array<mixed> $body
     */
    private static function assertMatchesSchema(string $name, array $body): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        self::assertIsArray($spec['components']['schemas'][$name] ?? null, $name.' must be in openapi.json.');
        self::matchSchema($spec['components']['schemas'], $name, $body);
    }

    /**
     * @param array<mixed> $schemas
     * @param array<mixed> $body
     */
    private static function matchSchema(array $schemas, string $name, array $body): void
    {
        $schema = $schemas[$name] ?? null;
        self::assertIsArray($schema, $name);
        self::assertIsArray($schema['properties']);
        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($body), $name);
        foreach ($schema['properties'] as $property => $definition) {
            self::assertIsArray($definition);
            $items = \is_array($definition['items'] ?? null) ? $definition['items'] : null;
            $ref = $definition['$ref'] ?? ($items['$ref'] ?? null);
            if (null === $ref && \is_array($definition['allOf'] ?? null) && \is_array($definition['allOf'][0] ?? null)) {
                $ref = $definition['allOf'][0]['$ref'] ?? null;
            }
            $value = $body[$property];
            if (!\is_string($ref) || !\is_array($value)) {
                continue;
            }
            foreach (null === $items ? [$value] : $value as $item) {
                // An item of a string schema (a status) has no keys to check.
                if (\is_array($item)) {
                    self::matchSchema($schemas, substr($ref, \strlen('#/components/schemas/')), $item);
                }
            }
        }
    }

    private static function assertUtc(mixed $moment, string $what = 'moment'): void
    {
        self::assertIsString($moment, $what);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $moment, $what.': RFC 3339 in UTC');
    }
}
