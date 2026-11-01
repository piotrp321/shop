<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pricing;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * GET /api/v1/prices on PostgreSQL (shop_test, rolled back after each test). The id list is sent as a repeated
 * `productId` key, as the generated client of the store does.
 */
final class PricesEndpointTest extends WebTestCase
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

    public function testReturnsTheGrossOfEachRequestedProductWithoutAToken(): void
    {
        $book = $this->insertPrice(5490, 'printed_book', 500);
        $shoes = $this->insertPrice(12300, 'standard', 2300);

        $body = $this->get([$book, $shoes]);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            ['items' => [
                ['productId' => $book, 'gross' => 5490, 'currency' => 'PLN'],
                ['productId' => $shoes, 'gross' => 12300, 'currency' => 'PLN'],
            ]],
            $body,
        );
        self::assertIsInt($this->items($body)[0]['gross']);
        $this->assertKeysMatchSchema($body, 'PriceList');
        $this->assertKeysMatchSchema($this->items($body)[0], 'PriceItem');
    }

    public function testResponseIsPubliclyCachedForSixtySeconds(): void
    {
        $this->get([$this->insertPrice(5490, 'printed_book', 500)]);

        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('public'));
        self::assertSame('60', $headers->getCacheControlDirective('max-age'));
        self::assertSame('max-age=60, public', $headers->get('Cache-Control'));
    }

    public function testSkipsProductsWithoutAnUsablePrice(): void
    {
        $current = $this->insertPrice(5490, 'printed_book', 500);
        $outdatedRate = $this->insertPrice(5490, 'printed_book', 800);
        $withoutPrice = (string) Uuid::v7();
        $unknown = (string) Uuid::v4();

        $body = $this->get([$outdatedRate, $withoutPrice, $current, $unknown]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$current], array_column($this->items($body), 'productId'));
    }

    public function testAnswersAnEmptyListWhenNoProductHasAPrice(): void
    {
        self::assertSame(['items' => []], $this->get([(string) Uuid::v7()]));
        self::assertResponseStatusCodeSame(200);
    }

    public function testEveryRepeatedKeyCounts(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->insertPrice(1000 + $i, 'standard', 2300);
        }

        // Written by hand, so the test does not depend on how PHP or Symfony would rebuild the query string.
        $this->client->request('GET', '/api/v1/prices?productId='.$ids[0].'&productId='.$ids[1].'&productId='.rawurlencode($ids[2]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame($ids, array_column($this->items($this->json()), 'productId'));
    }

    public function testAcceptsFortyEightIds(): void
    {
        $first = $this->insertPrice(5490, 'printed_book', 500);
        $ids = [$first, ...array_map(static fn (): string => (string) Uuid::v7(), range(1, 47))];

        $body = $this->get($ids);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$first], array_column($this->items($body), 'productId'));
    }

    #[DataProvider('rejectedRequests')]
    public function testRejectsABadIdListWithProblemDetails(string $query): void
    {
        $this->client->request('GET', '/api/v1/prices'.$query);

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

    private function insertPrice(int $gross, string $taxClass, int $basisPoints): string
    {
        $id = (string) Uuid::v7();
        $divisor = 10000 + $basisPoints;
        $net = intdiv(2 * $gross * 10000 + $divisor, 2 * $divisor);
        $this->connection->executeStatement(
            'INSERT INTO pricing_product_price (product_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, now())',
            [$id, 'PLN', $gross, $net, $gross - $net, $basisPoints, $taxClass],
        );

        return $id;
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
        $schema = $spec['components']['schemas'][$schemaName];
        self::assertIsArray($schema);
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
        $this->client->request('GET', '/api/v1/prices?'.implode('&', array_map(static fn (string $id): string => 'productId='.$id, $ids)));

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
