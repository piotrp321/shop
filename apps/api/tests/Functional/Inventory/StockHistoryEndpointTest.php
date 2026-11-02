<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /api/v1/admin/products/{productId}/stock-history on PostgreSQL (shop_test, rolled back after each test).
 */
final class StockHistoryEndpointTest extends WebTestCase
{
    use Factories;
    use StockEndpointTestTrait;

    private const string UNKNOWN_PRODUCT = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    public function testListsTheEntriesNewestFirstWithTheirDetails(): void
    {
        $productId = $this->product();
        $this->entry($productId, '2026-10-01 10:00:00+00:00', 20, ['source' => 'seed', 'reference' => 'seed:'.$productId, 'physical_after' => 20]);
        $this->entry($productId, '2026-10-03 12:30:00+02:00', -3, ['type' => 'correction', 'reason' => 'damage', 'note' => 'Zalane', 'physical_after' => 17, 'source' => 'staff', 'actor_id' => 'sub-123', 'actor_name' => 'Anna Admin']);
        $this->entry($productId, '2026-10-02 10:00:00+00:00', 0, ['type' => 'reservation', 'reserved_delta' => 4, 'physical_after' => 20, 'reserved_after' => 4, 'reference' => 'order-1', 'source' => 'system']);
        $this->entry($this->product(), '2026-10-04 10:00:00+00:00', 99);

        $body = $this->get($productId);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertNull($body['next']);
        $items = $this->items($body);
        self::assertSame(
            [
                ['occurredAt' => '2026-10-03T10:30:00+00:00', 'type' => 'correction', 'physicalDelta' => -3, 'reservedDelta' => 0, 'physicalAfter' => 17, 'reservedAfter' => 0, 'reason' => 'damage', 'note' => 'Zalane', 'reference' => null, 'source' => 'staff', 'actorName' => 'Anna Admin'],
                ['occurredAt' => '2026-10-02T10:00:00+00:00', 'type' => 'reservation', 'physicalDelta' => 0, 'reservedDelta' => 4, 'physicalAfter' => 20, 'reservedAfter' => 4, 'reason' => null, 'note' => null, 'reference' => 'order-1', 'source' => 'system', 'actorName' => null],
                ['occurredAt' => '2026-10-01T10:00:00+00:00', 'type' => 'receipt', 'physicalDelta' => 20, 'reservedDelta' => 0, 'physicalAfter' => 20, 'reservedAfter' => 0, 'reason' => null, 'note' => null, 'reference' => 'seed:'.$productId, 'source' => 'seed', 'actorName' => null],
            ],
            $items,
        );
        $this->assertKeysMatchSchema($body, 'StockHistoryResponse');
        $this->assertKeysMatchSchema($items[0], 'StockHistoryItemResponse');
        self::assertStringNotContainsString('sub-123', (string) $this->client->getResponse()->getContent());
        $this->assertPrivateAndNotStored();
    }

    public function testAProductWithoutAStockRecordHasAnEmptyHistory(): void
    {
        self::assertSame(['items' => [], 'next' => null], $this->get($this->product()));
        self::assertResponseStatusCodeSame(200);
    }

    public function testDefaultPageHoldsTwentyFiveEntriesAndTheCursorLeadsToTheRest(): void
    {
        $productId = $this->product();
        for ($n = 1; $n <= 26; ++$n) {
            $this->entry($productId, \sprintf('2026-10-01 10:%02d:00+00:00', $n), $n);
        }

        $first = $this->get($productId);
        $second = $this->get($productId, 'after='.$this->next($first));

        self::assertCount(25, $this->items($first));
        self::assertSame(26, $this->items($first)[0]['physicalDelta']);
        self::assertIsString($first['next']);
        self::assertSame([1], array_column($this->items($second), 'physicalDelta'));
        self::assertNull($second['next']);
    }

    public function testPagesWithPerPageWithoutRepeatingOrSkippingEntries(): void
    {
        $productId = $this->product();
        foreach ([1, 2, 3, 4, 5] as $n) {
            // Entries within one second: the later one has the greater, time-ordered id and comes first.
            $this->entry($productId, '2026-10-01 10:00:00+00:00', $n);
        }

        $seen = [];
        $query = 'perPage=2';
        for ($page = 0; $page < 5; ++$page) {
            $body = $this->get($productId, $query);
            array_push($seen, ...array_column($this->items($body), 'physicalDelta'));
            if (null === $body['next']) {
                break;
            }
            $query = 'perPage=2&after='.$this->next($body);
        }

        self::assertSame([5, 4, 3, 2, 1], $seen);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function staffRoles(): iterable
    {
        yield 'admin' => [['admin']];
        yield 'marketing' => [['marketing']];
        yield 'support' => [['support']];
        yield 'warehouse' => [['warehouse']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('staffRoles')]
    public function testEveryStaffRoleCanReadTheHistory(array $roles): void
    {
        $productId = $this->product();
        $this->entry($productId, '2026-10-01 10:00:00+00:00', 5);

        $body = $this->get($productId, '', TestTokenFactory::staff($roles));

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->items($body));
    }

    public function testWithoutATokenTheRequestIsUnauthorized(): void
    {
        $this->request($this->product(), '', null);

        $this->assertUnauthorized();
    }

    public function testATokenOfTheShopRealmIsUnauthorized(): void
    {
        $this->get($this->product(), '', TestTokenFactory::customer());

        $this->assertUnauthorized();
    }

    public function testAnUnknownProductIsNotFoundEvenWithHistoryLeftBehind(): void
    {
        $this->entry(self::UNKNOWN_PRODUCT, '2026-10-01 10:00:00+00:00', 5);

        $this->get(self::UNKNOWN_PRODUCT);

        $problem = $this->assertProblem(404);
        self::assertSame('Product not found.', $problem['detail']);
        self::assertStringNotContainsString(self::UNKNOWN_PRODUCT, (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsThatAreNotUuids(): iterable
    {
        yield 'a word' => ['shoes'];
        yield 'a uuid with a suffix' => [self::UNKNOWN_PRODUCT.'x'];
        yield 'a uuid without dashes' => [str_replace('-', '', self::UNKNOWN_PRODUCT)];
    }

    #[DataProvider('idsThatAreNotUuids')]
    public function testAProductIdThatIsNotAUuidIsNotFound(string $productId): void
    {
        $this->get($productId);

        $this->assertProblem(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedQueries(): iterable
    {
        yield 'zero per page' => ['perPage=0'];
        yield 'per page over 100' => ['perPage=101'];
        yield 'negative per page' => ['perPage=-1'];
        yield 'per page not a number' => ['perPage=many'];
        yield 'malformed cursor' => ['after=garbage!'];
        yield 'cursor of another shape' => ['after=aGVsbG8'];
        yield 'cursor of the stock list' => ['after=eyJuIjoiQmlkb24iLCJpIjoiMDE5MmYwYzQtN2MxYS03YzRlLTlhNmUtM2YxZDJiNGM1YTYwIn0'];
        yield 'cursor with year zero' => ['after='.self::cursor('{"t":"0000-01-01T00:00:00.000000+00:00","i":"0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60"}')];
        yield 'cursor with a NUL byte in the time' => ['after='.self::cursor('{"t":"2026-10-01T10:00:00.000000+00:00\u0000","i":"0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60"}')];
        yield 'cursor with a time zone offset out of range' => ['after='.self::cursor('{"t":"2026-10-01T10:00:00.000000+16:00","i":"0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60"}')];
        yield 'empty cursor' => ['after='];
        yield 'cursor as an array' => ['after[]=abc'];
    }

    #[DataProvider('rejectedQueries')]
    public function testBadParametersAreRejectedWithProblemDetails(string $query): void
    {
        $productId = $this->product();
        $this->entry($productId, '2026-10-01 10:00:00+00:00', 5);

        $this->get($productId, $query);

        $this->assertProblem(400);
    }

    public function testBoundariesOfThePageSizeAreAccepted(): void
    {
        $productId = $this->product();

        foreach (['perPage=1', 'perPage=100'] as $query) {
            $this->get($productId, $query);

            self::assertResponseStatusCodeSame(200, $query);
        }
    }

    private static function cursor(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $columns more columns of the movement row
     */
    private function entry(string $productId, string $occurredAt, int $physicalDelta, array $columns = []): void
    {
        $columns += [
            'type' => 'receipt', 'reserved_delta' => 0, 'physical_after' => $physicalDelta, 'reserved_after' => 0,
            'reason' => null, 'note' => null, 'reference' => null, 'source' => 'seed', 'actor_id' => null, 'actor_name' => null,
        ];
        $this->connection->executeStatement(
            'INSERT INTO inventory_stock_movement (id, product_id, type, physical_delta, reserved_delta, physical_after, reserved_after, reason, note, reference, source, actor_id, actor_name, occurred_at) '
            .'VALUES (:id, :product_id, :type, :physical_delta, :reserved_delta, :physical_after, :reserved_after, :reason, :note, :reference, :source, :actor_id, :actor_name, :occurred_at)',
            ['id' => (string) Uuid::v7(), 'product_id' => $productId, 'physical_delta' => $physicalDelta, 'occurred_at' => $occurredAt] + $columns,
        );
    }

    /**
     * Requests the history as a staff member (an administrator unless the token says otherwise).
     *
     * @return array<mixed>
     */
    private function get(string $productId, string $query = '', ?string $token = null): array
    {
        return $this->request($productId, $query, 'Bearer '.($token ?? TestTokenFactory::staff(['admin'])));
    }

    /**
     * @return array<mixed>
     */
    private function request(string $productId, string $query, ?string $authorization): array
    {
        $server = null === $authorization ? [] : ['HTTP_AUTHORIZATION' => $authorization];
        $this->client->request('GET', '/api/v1/admin/products/'.$productId.'/stock-history'.('' === $query ? '' : '?'.$query), server: $server);

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<mixed> $body
     */
    private function next(array $body): string
    {
        self::assertIsString($body['next']);

        return $body['next'];
    }

    /**
     * @param array<mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function items(array $body): array
    {
        self::assertIsArray($body['items']);
        self::assertTrue(array_is_list($body['items']));

        /** @var list<array<string, mixed>> $items */
        $items = $body['items'];

        return $items;
    }
}
