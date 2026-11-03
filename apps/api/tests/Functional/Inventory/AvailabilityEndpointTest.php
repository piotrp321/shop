<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * GET /api/v1/availability on PostgreSQL (shop_test, rolled back after each test). The id list is sent as a repeated
 * `productId` key, as the generated client of the store does.
 */
final class AvailabilityEndpointTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
    }

    public function testAnswersTheLevelOfEachRequestedProductWithoutAToken(): void
    {
        $out = StockFixture::stock($this->connection, 0);
        $lowFloor = StockFixture::stock($this->connection, 1);
        $lowCeiling = StockFixture::stock($this->connection, 5);
        $inStock = StockFixture::stock($this->connection, 6);
        $reservedDown = StockFixture::stock($this->connection, 10, 5);

        $body = $this->get([$out, $lowFloor, $lowCeiling, $inStock, $reservedDown]);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            ['items' => [
                ['productId' => $out, 'level' => 'out_of_stock'],
                ['productId' => $lowFloor, 'level' => 'low_stock'],
                ['productId' => $lowCeiling, 'level' => 'low_stock'],
                ['productId' => $inStock, 'level' => 'in_stock'],
                ['productId' => $reservedDown, 'level' => 'low_stock'],
            ]],
            $body,
        );
    }

    public function testAProductWithoutAStockOrUnknownToCatalogIsOutOfStock(): void
    {
        $inStock = StockFixture::stock($this->connection, 20);
        $unknown = (string) Uuid::v7();

        $body = $this->get([$unknown, $inStock]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['items' => [
                ['productId' => $unknown, 'level' => 'out_of_stock'],
                ['productId' => $inStock, 'level' => 'in_stock'],
            ]],
            $body,
        );
    }

    public function testNeverReturnsQuantities(): void
    {
        $productId = StockFixture::stock($this->connection, 7, 2);

        $this->get([$productId]);

        $content = (string) $this->client->getResponse()->getContent();
        foreach (['physical', 'reserved', 'available', 'quantity', 'version'] as $word) {
            self::assertStringNotContainsString($word, $content);
        }
        $item = $this->items($this->json())[0];
        self::assertSame(['productId', 'level'], array_keys($item));
        $this->assertKeysMatchSchema($item, 'AvailabilityItemResponse');
        $this->assertKeysMatchSchema($this->json(), 'AvailabilityListResponse');
    }

    public function testAListOfIdsAnswersARepeatedIdOnceInTheOrderOfTheRequest(): void
    {
        $first = StockFixture::stock($this->connection, 3);
        $second = StockFixture::stock($this->connection, 30);

        // Written by hand, so the test does not depend on how PHP or Symfony would rebuild the query string.
        $this->client->request('GET', '/api/v1/availability?productId='.$first.'&productId='.rawurlencode($second).'&productId='.$first);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$first, $second], array_column($this->items($this->json()), 'productId'));
    }

    public function testResponseIsPubliclyCachedForSixtySeconds(): void
    {
        $this->get([StockFixture::stock($this->connection, 3)]);

        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('public'));
        self::assertSame('60', $headers->getCacheControlDirective('max-age'));
        self::assertSame('max-age=60, public', $headers->get('Cache-Control'));
    }

    public function testAcceptsFortyEightIds(): void
    {
        $first = StockFixture::stock($this->connection, 3);
        $ids = [$first, ...array_map(static fn (): string => (string) Uuid::v7(), range(1, 47))];

        $body = $this->get($ids);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($ids, array_column($this->items($body), 'productId'));
        self::assertSame('low_stock', $this->items($body)[0]['level']);
    }

    public function testAnInvalidTokenIsStillRejected(): void
    {
        $this->client->request('GET', '/api/v1/availability?productId='.Uuid::v7(), server: ['HTTP_AUTHORIZATION' => 'Bearer not-a-token']);

        self::assertResponseStatusCodeSame(401);
    }

    #[DataProvider('rejectedRequests')]
    public function testRejectsABadIdListWithProblemDetails(string $query): void
    {
        $this->client->request('GET', '/api/v1/availability'.$query);

        self::assertResponseStatusCodeSame(400);
        $response = $this->client->getResponse();
        self::assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        $problem = $this->json();
        self::assertSame(400, $problem['status']);
        self::assertSame('Bad Request', $problem['title']);
        self::assertIsArray($problem['violations']);
        self::assertNotSame([], $problem['violations']);
        self::assertStringNotContainsString('secret-input', (string) $response->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedRequests(): iterable
    {
        yield 'no id' => [''];
        yield 'other parameter only' => ['?category=x'];
        yield 'empty value' => ['?productId='];
        yield '49 ids' => ['?'.implode('&', array_map(static fn (int $n): string => 'productId='.\sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', $n), range(1, 49)))];
        yield 'not a uuid' => ['?productId=secret-input'];
        yield 'one of many not a uuid' => ['?productId=0192f0c4-7c1a-7c4e-9a6e-000000000001&productId=secret-input'];
        yield 'bracket form is not the contract' => ['?productId[]=0192f0c4-7c1a-7c4e-9a6e-000000000001'];
    }

    /**
     * @param array<mixed> $object
     */
    private function assertKeysMatchSchema(array $object, string $schemaName): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        $schema = $spec['components']['schemas'][$schemaName] ?? null;
        self::assertIsArray($schema, $schemaName.' must be in openapi.json.');
        self::assertIsArray($schema['properties']);
        self::assertIsArray($schema['required']);

        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($object), $schemaName.' keys must match the schema.');
        self::assertEqualsCanonicalizing($schema['required'], array_keys($object), $schemaName.' requires every key.');
    }

    /**
     * @param list<string> $ids
     *
     * @return array<mixed>
     */
    private function get(array $ids): array
    {
        $this->client->request('GET', '/api/v1/availability?'.implode('&', array_map(static fn (string $id): string => 'productId='.$id, $ids)));

        return $this->json();
    }

    /** @return array<mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
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
