<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ordering;

use App\Tests\Integration\Ordering\OrderingFixture;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * GET /api/v1/admin/customers and /api/v1/admin/customers/{customerId} (spec ADMIN-FEATURES, section 5): an
 * administrator and support see the customers, the warehouse and marketing get 403; the list searches a fragment of
 * the email or the name, sorts by the closed list of keys with a keyset cursor and counts the first page; the card
 * has the contact, the summary and the addresses of the last orders; every response is `private, no-store`. On
 * shop_test, rolled back after each test.
 */
final class BackofficeCustomersEndpointTest extends WebTestCase
{
    use BackofficeOrderEndpointTestTrait;

    private const string PATH = '/api/v1/admin/customers';

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function viewers(): iterable
    {
        yield 'admin' => [['admin']];
        yield 'support' => [['support']];
        yield 'support with warehouse' => [['warehouse', 'support']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('viewers')]
    public function testAnAdministratorAndSupportListTheCustomersNewestLastOrderFirst(array $roles): void
    {
        $this->customers();

        $list = $this->list('', self::staff($roles));

        self::assertResponseIsSuccessful();
        $this->assertPrivateAndNotStored();
        self::assertMatchesSchema('BackofficeCustomerListResponse', $list);
        self::assertSame(['value' => 3, 'capped' => false], $list['total']);
        self::assertNull($list['nextCursor']);
        self::assertIsArray($list['items']);
        self::assertSame([
            'customerId' => 'kc-c',
            'email' => 'ewa@example.com',
            'fullName' => 'Ewa Nowakowska',
            'orderCount' => 1,
            'paidGross' => 0,
            'currency' => 'PLN',
            'lastOrderAt' => '2026-11-03T10:00:00+00:00',
        ], $list['items'][0]);
        self::assertMatchesSchema('BackofficeCustomerListItemResponse', $list['items'][0]);
        self::assertSame(['kc-c', 'kc-a', 'kc-b'], self::ids($list));
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('viewers')]
    public function testAnAdministratorAndSupportSeeTheCardOfACustomer(array $roles): void
    {
        $this->customers();
        OrderingFixture::order($this->connection, customerId: 'kc-a', placedAt: '2026-11-01 10:00:00+00:00', street: 'Długa 1');
        OrderingFixture::order($this->connection, customerId: 'kc-a', placedAt: '2026-11-02 10:00:00+00:00', street: 'Krótka 2');

        $card = $this->getJson(self::PATH.'/kc-a', self::staff($roles));

        self::assertResponseIsSuccessful();
        $this->assertPrivateAndNotStored();
        self::assertMatchesSchema('BackofficeCustomerResponse', $card);
        self::assertSame([
            'customerId' => 'kc-a',
            'contact' => ['email' => 'anna.nowak@example.com', 'fullName' => 'Anna Nowak', 'phone' => '+48600100200'],
            'summary' => [
                'orderCount' => 3,
                'paidCount' => 2,
                'paidGross' => 24_600,
                'currency' => 'PLN',
                'firstOrderAt' => '2026-10-01T10:00:00+00:00',
                'lastOrderAt' => '2026-11-02T10:00:00+00:00',
            ],
            'addresses' => [
                ['street' => 'Krótka 2', 'postalCode' => '00-950', 'city' => 'Warszawa', 'country' => 'PL'],
                ['street' => 'Długa 1', 'postalCode' => '00-950', 'city' => 'Warszawa', 'country' => 'PL'],
            ],
        ], $card);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function nonViewers(): iterable
    {
        yield 'warehouse' => [['warehouse']];
        yield 'marketing' => [['marketing']];
        yield 'warehouse and marketing' => [['warehouse', 'marketing']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('nonViewers')]
    public function testTheWarehouseAndMarketingAreForbiddenAlsoWithBadParameters(array $roles): void
    {
        $this->customers();

        $this->assertProblem(403, $this->list('', self::staff($roles)));
        self::assertStringNotContainsString('anna.nowak', (string) $this->response()->getContent());
        $this->assertProblem(403, $this->list('q=a&sort=bad&after=garbage!', self::staff($roles)));
        $this->assertProblem(403, $this->getJson(self::PATH.'/kc-a', self::staff($roles)));
        self::assertStringNotContainsString('anna.nowak', (string) $this->response()->getContent());
    }

    public function testATokenIsRequiredAndACustomerTokenIsNotAccepted(): void
    {
        $this->assertUnauthorized($this->list('', null));
        $this->assertUnauthorized($this->list('', TestTokenFactory::customer()));
        $this->assertUnauthorized($this->getJson(self::PATH.'/kc-a', TestTokenFactory::customer()));
    }

    public function testAnUnknownCustomerIsA404(): void
    {
        $this->customers();
        // A guest is no customer, nor an account without orders.
        OrderingFixture::order($this->connection, email: 'gosc@example.com');

        foreach (['/kc-unknown', '/gosc@example.com', '/'.str_repeat('a', 256), '/kc-a%00'] as $path) {
            $problem = $this->getJson(self::PATH.$path, self::staff(['support']));
            $this->assertProblem(404, $problem);
            self::assertSame('Klient nie istnieje.', $problem['detail'] ?? null, $path);
        }
    }

    public function testTheSearchFindsAFragmentOfTheEmailOrTheName(): void
    {
        $this->customers();

        self::assertSame(['kc-c', 'kc-a'], self::ids($this->list('q=NOWAK', self::staff(['admin']))));
        self::assertSame(['kc-b'], self::ids($this->list('q='.rawurlencode(' bartek@ '), self::staff(['admin']))));
        self::assertSame(['kc-a'], self::ids($this->list('q=nna+N&sort=-lastOrderAt', self::staff(['admin']))));
        $none = $this->list('q=zzz', self::staff(['admin']));
        self::assertSame([], $none['items']);
        self::assertSame(['value' => 0, 'capped' => false], $none['total']);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function sorts(): iterable
    {
        yield 'the newest last order' => ['-lastOrderAt', ['kc-c', 'kc-a', 'kc-b']];
        yield 'the name' => ['name', ['kc-a', 'kc-b', 'kc-c']];
        yield 'the most orders' => ['-orderCount', ['kc-a', 'kc-b', 'kc-c']];
        yield 'the highest paid value' => ['-paidGross', ['kc-a', 'kc-b', 'kc-c']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('sorts')]
    public function testEverySortPagesWithItsCursorAndTheFirstPageHasTheTotal(string $sort, array $expected): void
    {
        $this->customers();

        $first = $this->list('perPage=2&sort='.$sort, self::staff(['admin']));
        self::assertSame(['value' => 3, 'capped' => false], $first['total']);
        self::assertIsString($first['nextCursor']);
        $second = $this->list('perPage=2&sort='.$sort.'&after='.$first['nextCursor'], self::staff(['admin']));

        self::assertNull($second['total']);
        self::assertNull($second['nextCursor']);
        self::assertSame($expected, [...self::ids($first), ...self::ids($second)]);
    }

    public function testACursorOfAnotherSortIsA400AtAfter(): void
    {
        $this->customers();
        $first = $this->list('perPage=1&sort=name', self::staff(['admin']));
        self::assertIsString($first['nextCursor']);

        $problem = $this->list('perPage=1&sort=-orderCount&after='.$first['nextCursor'], self::staff(['admin']));

        self::assertSame(['after'], $this->violationPaths(400, $problem));
        self::assertSame('Kursor strony jest nieprawidłowy.', self::firstViolationTitle($problem));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function badParameters(): iterable
    {
        $term = 'Szukana fraza to fragment e-maila albo imienia i nazwiska, od 3 do 100 znaków bez znaków sterujących.';
        $sort = 'Sortowanie może mieć tylko wartość -lastOrderAt, name, -orderCount albo -paidGross.';
        $perPage = 'Liczba wyników na stronie to liczba całkowita od 1 do 100.';
        $cursor = 'Kursor strony jest nieprawidłowy.';

        yield 'a term of two characters' => ['q=an', 'q', $term];
        yield 'a term of no-break spaces only' => ['q='.rawurlencode("\u{00A0}\u{00A0}\u{00A0}"), 'q', $term];
        yield 'a term of 101 characters' => ['q='.str_repeat('a', 101), 'q', $term];
        yield 'a term with a NUL' => ['q=ann%00a', 'q', $term];
        yield 'a term with a line separator' => ['q='.rawurlencode("ann\u{2028}a"), 'q', $term];
        yield 'a term as an array' => ['q[]=anna', 'q', $term];
        yield 'a sort outside the list' => ['sort=-name', 'sort', $sort];
        yield 'a sort as an array' => ['sort[]=name', 'sort', $sort];
        yield 'the search with the sort by name' => ['q=anna&sort=name', 'sort', 'Wyszukiwanie łączy się tylko z sortowaniem od ostatniego zamówienia.'];
        yield 'the search with the sort by orders' => ['q=anna&sort=-orderCount', 'sort', 'Wyszukiwanie łączy się tylko z sortowaniem od ostatniego zamówienia.'];
        yield 'per page zero' => ['perPage=0', 'perPage', $perPage];
        yield 'per page above the maximum' => ['perPage=101', 'perPage', $perPage];
        yield 'per page not a number' => ['perPage=many', 'perPage', $perPage];
        yield 'per page as an array' => ['perPage[]=5', 'perPage', $perPage];
        yield 'a malformed cursor' => ['after=garbage!', 'after', $cursor];
        yield 'an empty cursor' => ['after=', 'after', $cursor];
        yield 'a cursor too long' => ['after='.str_repeat('a', 2049), 'after', $cursor];
        yield 'a cursor as an array' => ['after[]=x', 'after', $cursor];
    }

    #[DataProvider('badParameters')]
    public function testBadParametersAreA400InPolishAtTheirField(string $query, string $path, string $title): void
    {
        $problem = $this->list($query, self::staff(['admin']));

        self::assertSame([$path], $this->violationPaths(400, $problem));
        self::assertSame($title, self::firstViolationTitle($problem));
        self::assertStringNotContainsString('garbage', (string) $this->response()->getContent());
    }

    /**
     * Three customers: kc-a with the most orders and the highest value, kc-c with the newest last order.
     */
    private function customers(): void
    {
        $this->connection->executeStatement('DELETE FROM ordering_customer');
        foreach ([
            ['kc-a', 'anna.nowak@example.com', 'Anna Nowak', 3, 2, 24_600, '2026-10-01 10:00:00+00', '2026-11-02 10:00:00+00'],
            ['kc-b', 'bartek@example.com', 'bartek Lis', 2, 1, 12_300, '2026-10-02 10:00:00+00', '2026-11-01 10:00:00+00'],
            ['kc-c', 'ewa@example.com', 'Ewa Nowakowska', 1, 0, 0, '2026-11-03 10:00:00+00', '2026-11-03 10:00:00+00'],
        ] as $row) {
            $this->connection->executeStatement(
                "INSERT INTO ordering_customer (customer_id, email, full_name, phone, order_count, paid_count, paid_gross, first_order_at, last_order_at, last_order_id)
                 VALUES (?, ?, ?, '+48600100200', ?, ?, ?, ?, ?, gen_random_uuid())",
                $row,
            );
        }
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
    private static function ids(array $list): array
    {
        self::assertIsArray($list['items'] ?? null);

        return array_map(static function (mixed $item): string {
            self::assertIsArray($item);
            self::assertIsString($item['customerId']);

            return $item['customerId'];
        }, array_values($list['items']));
    }

    /**
     * @param array<mixed> $problem
     */
    private static function firstViolationTitle(array $problem): string
    {
        self::assertIsArray($problem['violations'] ?? null);
        $violation = $problem['violations'][0] ?? null;
        self::assertIsArray($violation);
        self::assertIsString($violation['title']);

        return $violation['title'];
    }
}
