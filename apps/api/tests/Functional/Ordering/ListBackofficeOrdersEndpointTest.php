<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Tests\Integration\Ordering\BackofficeOrderFixture;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/v1/admin/orders on PostgreSQL (shop_test, rolled back after each test; the orders of the test are the only
 * ones in its transaction).
 */
final class ListBackofficeOrdersEndpointTest extends WebTestCase
{
    use BackofficeOrderEndpointTestTrait {
        setUp as setUpClient;
    }

    private const string PATH = '/api/v1/admin/orders';

    protected function setUp(): void
    {
        $this->setUpClient();
        BackofficeOrderFixture::noOrders($this->connection);
    }

    public function testNewestFirstWithTheFieldsOfTheList(): void
    {
        $older = $this->order('paid', '-2 days', 'R-100001', quantities: [2, 3]);
        $newer = $this->order('cancelled_by_staff', '-1 day', 'R-100002', email: 'Anna.Nowak@Example.com', refundDueAt: '-1 hour');

        $list = $this->list('', self::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
        self::assertMatchesSchema('BackofficeOrderList', $list);
        self::assertNull($list['nextCursor']);
        self::assertIsArray($list['items']);
        self::assertSame([$newer, $older], array_column($list['items'], 'orderId'));
        [$cancelled, $paid] = $list['items'];
        self::assertIsArray($cancelled);
        self::assertIsArray($paid);
        self::assertSame(
            ['R-100002', 'cancelled', 'Anna.Nowak@Example.com', 'Jan Kowalski', 1_000, 'PLN', 1],
            [$cancelled['number'], $cancelled['status'], $cancelled['email'], $cancelled['fullName'], $cancelled['totalGross'], $cancelled['currency'], $cancelled['itemCount']],
        );
        self::assertUtc($cancelled['placedAt']);
        self::assertIsArray($cancelled['refund']);
        self::assertSame([1_000, null, true], [$cancelled['refund']['amount'], $cancelled['refund']['completedAt'], $cancelled['refund']['overdue']]);
        self::assertUtc($cancelled['refund']['dueAt']);
        self::assertSame([5_000, 5, null], [$paid['totalGross'], $paid['itemCount'], $paid['refund']]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function numbers(): iterable
    {
        yield 'as stored' => ['R-100002'];
        yield 'in lower case' => ['r-100002'];
        yield 'with spaces around' => ['%20R-100002%20'];
    }

    #[DataProvider('numbers')]
    public function testSearchesTheExactNumberRegardlessOfLetterCase(string $q): void
    {
        $this->order('paid', '-3 days', 'R-1000020');
        $wanted = $this->order('paid', '-2 days', 'R-100002');
        $this->order('paid', '-1 day', 'R-100003');

        self::assertSame([$wanted], $this->ids($this->list('q='.$q, self::staff(['support']))));
    }

    public function testSearchesTheExactEmailRegardlessOfLetterCase(): void
    {
        $first = $this->order('paid', '-3 days', 'R-100001', email: 'jan.kowalski@example.com');
        $second = $this->order('new', '-2 days', 'R-100002', email: 'Jan.Kowalski@Example.com');
        $this->order('paid', '-1 day', 'R-100003', email: 'kowalski@example.com');

        self::assertSame([$second, $first], $this->ids($this->list('q='.rawurlencode('JAN.KOWALSKI@example.COM'), self::staff(['warehouse']))));
        self::assertSame([], $this->ids($this->list('q='.rawurlencode('example.com'), self::staff(['warehouse']))), 'no part of an email');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherSearches(): iterable
    {
        yield 'a name' => ['Kowalski'];
        yield 'the digits of a number' => ['100001'];
        yield 'a part of a number' => ['R-1000'];
        yield 'the longest term' => [str_repeat('a', 254)];
    }

    #[DataProvider('otherSearches')]
    public function testAnyOtherSearchIsAnEmptyList(string $q): void
    {
        $this->order('paid', '-1 day', 'R-100001');

        $list = $this->list('q='.$q, self::staff(['admin']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['items' => [], 'nextCursor' => null], $list);
    }

    public function testTheStatusFilterCombinesWithTheSearch(): void
    {
        $shipped = $this->order('shipped', '-3 days', 'R-100001');
        $this->order('paid', '-2 days', 'R-100002');
        $alsoShipped = $this->order('shipped', '-1 day', 'R-100003', email: 'anna@example.com');

        self::assertSame([$alsoShipped, $shipped], $this->ids($this->list('status=shipped', self::staff(['admin']))));
        self::assertSame([$shipped], $this->ids($this->list('status=shipped&q=jan@example.com', self::staff(['admin']))));
        self::assertSame([], $this->ids($this->list('status=returned', self::staff(['admin']))));
    }

    public function testThePendingRefundsNearestDueDateFirstWithOverdueAndTheStatus(): void
    {
        $this->order('paid', '-10 days', 'R-100001');
        $returnedLater = $this->order('returned', '-9 days', 'R-100002', refundDueAt: '+5 days');
        $cancelledOverdue = $this->order('cancelled_by_staff', '-8 days', 'R-100003', refundDueAt: '-1 day');
        $returnedSooner = $this->order('returned', '-7 days', 'R-100004', refundDueAt: '+1 day');
        $this->order('returned', '-6 days', 'R-100005', refundDueAt: '-2 days', refundCompletedAt: '-3 days');

        $all = $this->list('refund=pending', self::staff(['support']));

        self::assertSame([$cancelledOverdue, $returnedSooner, $returnedLater], $this->ids($all));
        self::assertIsArray($all['items']);
        self::assertSame([true, false, false], array_map(static fn (mixed $item): mixed => \is_array($item) && \is_array($item['refund']) ? $item['refund']['overdue'] : null, $all['items']));
        self::assertSame([$returnedSooner, $returnedLater], $this->ids($this->list('refund=pending&status=returned', self::staff(['support']))));
        self::assertSame([$returnedSooner], $this->ids($this->list('refund=pending&status=returned&q=r-100004', self::staff(['support']))));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sorts(): iterable
    {
        yield 'newest first' => [''];
        yield 'pending refunds' => ['refund=pending&'];
    }

    #[DataProvider('sorts')]
    public function testKeysetPagingVisitsEveryOrderOnce(string $sort): void
    {
        $expected = [];
        foreach (['-5 days', '-4 days', '-4 days', '-3 days', '-2 days'] as $n => $placedAt) {
            $expected[] = $this->order('returned', $placedAt, 'R-'.(100_001 + $n), refundDueAt: 0 === $n % 2 ? '+1 day' : '+2 days');
        }
        $onePage = $this->ids($this->list($sort.'perPage=100', self::staff(['admin'])));

        $seen = [];
        $query = $sort.'perPage=2';
        for ($page = 0; $page < 5; ++$page) {
            $list = $this->list($query, self::staff(['admin']));
            self::assertResponseStatusCodeSame(200);
            $seen = [...$seen, ...$this->ids($list)];
            if (null === $list['nextCursor']) {
                break;
            }
            self::assertIsString($list['nextCursor']);
            $query = $sort.'perPage=2&after='.$list['nextCursor'];
        }

        self::assertSame($onePage, $seen, 'the pages together are the one page, without duplicates');
        self::assertEqualsCanonicalizing($expected, $seen);
        self::assertCount(5, array_unique($seen));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badParameters(): iterable
    {
        yield 'unknown status' => ['status=sent', 'status'];
        yield 'empty status' => ['status=', 'status'];
        yield 'status as an array' => ['status[]=paid', 'status'];
        yield 'unknown refund filter' => ['refund=done', 'refund'];
        yield 'empty refund filter' => ['refund=', 'refund'];
        yield 'empty term' => ['q=', 'q'];
        yield 'nothing but spaces' => ['q=%20%20%20', 'q'];
        yield 'term over 254 characters' => ['q='.str_repeat('a', 255), 'q'];
        yield 'term with a NUL character' => ['q=jan%00@example.com', 'q'];
        yield 'term as an array' => ['q[]=R-100001', 'q'];
        yield 'zero per page' => ['perPage=0', 'perPage'];
        yield 'per page over 100' => ['perPage=101', 'perPage'];
        yield 'per page not a number' => ['perPage=many', 'perPage'];
        yield 'malformed cursor' => ['after=garbage!', 'after'];
        yield 'cursor of another shape' => ['after=aGVsbG8', 'after'];
        yield 'empty cursor' => ['after=', 'after'];
        yield 'cursor too long' => ['after='.str_repeat('a', 2049), 'after'];
    }

    #[DataProvider('badParameters')]
    public function testBadParametersAreA400(string $query, string $path): void
    {
        $problem = $this->list($query, self::staff(['admin']));

        self::assertSame([$path], $this->violationPaths(400, $problem));
        self::assertStringNotContainsString('garbage', (string) $this->response()->getContent());
    }

    public function testACursorOfTheOtherSortIsA400(): void
    {
        $this->order('returned', '-2 days', 'R-100001', refundDueAt: '+1 day');
        $this->order('returned', '-1 day', 'R-100002', refundDueAt: '+2 days');
        $byDate = $this->list('perPage=1', self::staff(['admin']))['nextCursor'];
        $byRefund = $this->list('refund=pending&perPage=1', self::staff(['admin']))['nextCursor'];
        self::assertIsString($byDate);
        self::assertIsString($byRefund);

        self::assertSame(['after'], $this->violationPaths(400, $this->list('refund=pending&after='.$byDate, self::staff(['admin']))));
        self::assertSame(['after'], $this->violationPaths(400, $this->list('after='.$byRefund, self::staff(['admin']))));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function viewers(): iterable
    {
        yield 'admin' => [['admin']];
        yield 'support' => [['support']];
        yield 'warehouse' => [['warehouse']];
        yield 'marketing with warehouse' => [['marketing', 'warehouse']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('viewers')]
    public function testAdminSupportAndWarehouseList(array $roles): void
    {
        $this->order('paid', '-1 day', 'R-100001');

        self::assertCount(1, $this->ids($this->list('', self::staff($roles))));
        self::assertResponseStatusCodeSame(200);
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
    public function testMarketingAndStaffWithoutARoleAreForbiddenAlsoWithBadParameters(array $roles): void
    {
        $this->order('paid', '-1 day', 'R-100001');

        $this->assertProblem(403, $this->list('', self::staff($roles)));
        self::assertStringNotContainsString('R-100001', (string) $this->response()->getContent());
        $this->assertProblem(403, $this->list('perPage=0&after=garbage!', self::staff($roles)));
    }

    public function testATokenIsRequiredAndACustomerTokenIsNotAccepted(): void
    {
        $this->assertUnauthorized($this->list('', null));
        $this->assertUnauthorized($this->list('', TestTokenFactory::customer()));
    }

    /**
     * @return array<mixed>
     */
    private function list(string $query, ?string $token): array
    {
        return $this->getJson(self::PATH.('' === $query ? '' : '?'.$query), $token);
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<string>
     */
    private function ids(array $list): array
    {
        self::assertIsArray($list['items'] ?? null);

        return array_values(array_map(static function (mixed $item): string {
            \assert(\is_array($item) && \is_string($item['orderId']));

            return $item['orderId'];
        }, $list['items']));
    }

    /**
     * An order placed and due relative to now.
     *
     * @param 'new'|'paid'|'shipped'|'delivered'|'cancelled'|'cancelled_by_staff'|'returned' $status
     * @param list<int>                                                                      $quantities
     */
    private function order(string $status, string $placedAt, string $number, string $email = 'jan@example.com', array $quantities = [1], ?string $refundDueAt = null, ?string $refundCompletedAt = null): string
    {
        $moment = static fn (?string $relative): ?string => null === $relative ? null : new \DateTimeImmutable($relative)->format('Y-m-d H:i:sP');

        return BackofficeOrderFixture::order($this->connection, $status, (string) $moment($placedAt), $number, $email, $quantities, $moment($refundDueAt), $moment($refundCompletedAt));
    }
}
