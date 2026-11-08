<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\Query\SitemapChunkKey;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /api/v1/sitemap/product-chunks and /api/v1/sitemap/products on PostgreSQL (shop_test, rolled back after each
 * test). The test configuration cuts chunks of 3 products.
 */
final class SitemapEndpointsTest extends WebTestCase
{
    use Factories;

    private const string CHUNKS = '/api/v1/sitemap/product-chunks';
    private const string PRODUCTS = '/api/v1/sitemap/products';

    private KernelBrowser $client;
    private DoctrineProductRepository $products;
    private Category $category;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->products = new DoctrineProductRepository($entityManager);
        $this->category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($this->category);
    }

    public function testTheChunkListNeedsNoTokenAndHasTheDocumentedShape(): void
    {
        $this->createProducts(7);

        $body = $this->get(self::CHUNKS);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(['chunks'], array_keys($body));
        self::assertIsArray($body['chunks']);
        self::assertCount(3, $body['chunks']);
        foreach ($body['chunks'] as $chunk) {
            self::assertIsArray($chunk);
            self::assertSame(['key'], array_keys($chunk));
            self::assertIsString($chunk['key']);
            SitemapChunkKey::decode($chunk['key']);
        }
    }

    public function testTheChunkListOfAnEmptyCatalogueIsOneChunk(): void
    {
        $body = $this->get(self::CHUNKS);

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($body['chunks']);
        self::assertCount(1, $body['chunks']);
    }

    public function testTheChunkListIsKeptBrieflyByTheBrowserForAnHourByVarnishAndTaggedWithTheCatalog(): void
    {
        $this->get(self::CHUNKS);

        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('public'));
        self::assertSame('60', $headers->getCacheControlDirective('max-age'));
        self::assertSame('3600', $headers->getCacheControlDirective('s-maxage'));
        self::assertSame('catalog', $headers->get('xkey'));
    }

    public function testAChunkListsItsPublishedProductsWithTheTimeOfTheLastChange(): void
    {
        $this->createProduct(1, ProductStatus::Published, new \DateTimeImmutable('2026-05-06 07:08:09 +02:00'));
        $this->createProduct(2, ProductStatus::Draft);
        $this->createProduct(3, ProductStatus::Published, new \DateTimeImmutable('2026-05-07 00:00:00 UTC'));

        $body = $this->get(self::PRODUCTS.'?chunk='.$this->firstChunkKey());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([
            'items' => [
                ['slug' => 'product-1', 'updatedAt' => '2026-05-06T05:08:09+00:00'],
                ['slug' => 'product-3', 'updatedAt' => '2026-05-07T00:00:00+00:00'],
            ],
        ], $body);
    }

    public function testAChunkWithoutPublishedProductsHasAnEmptyList(): void
    {
        $this->createProduct(1, ProductStatus::Draft);

        $this->get(self::PRODUCTS.'?chunk='.$this->firstChunkKey());

        self::assertResponseStatusCodeSame(200);
        self::assertSame('{"items":[]}', $this->client->getResponse()->getContent());
    }

    public function testAChunkIsKeptByTheBrowserForFiveMinutesAndHasNoTags(): void
    {
        $this->createProducts(2);

        $this->get(self::PRODUCTS.'?chunk='.$this->firstChunkKey());

        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('public'));
        self::assertSame('300', $headers->getCacheControlDirective('max-age'));
        self::assertFalse($headers->hasCacheControlDirective('s-maxage'));
        self::assertFalse($headers->has('xkey'));
    }

    public function testBothAnswerAHeadRequestWithoutBody(): void
    {
        foreach ([self::CHUNKS, self::PRODUCTS.'?chunk='.$this->firstChunkKey()] as $path) {
            $this->client->request('HEAD', $path);

            self::assertResponseStatusCodeSame(200);
            self::assertSame('', $this->client->getResponse()->getContent());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidChunkQueries(): iterable
    {
        yield 'no chunk' => [''];
        yield 'empty chunk' => ['?chunk='];
        yield 'not a key' => ['?chunk=not-a-key'];
        yield 'an array' => ['?chunk[]=a'];
        yield 'a NUL' => ['?chunk=%00'];
        yield 'white space only' => ['?chunk=%C2%A0'];
        yield 'over-long' => ['?chunk='.str_repeat('A', 5000)];
        yield 'a key with an end below the start' => ['?chunk=eyJmIjoiZmZmZmZmZmYtMDAwMC00MDAwLTgwMDAtMDAwMDAwMDAwMDAyIiwidCI6IjAwMDAwMDAwLTAwMDAtNDAwMC04MDAwLTAwMDAwMDAwMDAwMSJ9'];
    }

    #[DataProvider('invalidChunkQueries')]
    public function testAMissingOrInvalidChunkIsABadRequestWithTheViolationInPolish(string $query): void
    {
        $this->client->request('GET', self::PRODUCTS.$query);

        self::assertResponseStatusCodeSame(400);
        $response = $this->client->getResponse();
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame(400, $problem['status']);
        self::assertIsArray($problem['violations']);
        self::assertCount(1, $problem['violations']);
        $violation = $problem['violations'][0];
        self::assertIsArray($violation);
        self::assertSame('chunk', $violation['propertyPath']);
        self::assertIsString($violation['title']);
        self::assertMatchesRegularExpression('/\bporcji\b/u', $violation['title']);
        $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        sort($directives);
        self::assertSame(['no-store', 'private'], $directives);
        self::assertFalse($response->headers->has('xkey'));
    }

    public function testTheChunkListOnlyAcceptsReads(): void
    {
        $this->client->request('POST', self::CHUNKS);

        self::assertResponseStatusCodeSame(405);
    }

    #[DataProvider('bothPaths')]
    public function testAnInvalidTokenIsUnauthorizedAndNotStored(string $path): void
    {
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer garbage']);

        self::assertResponseStatusCodeSame(401);
        $headers = $this->client->getResponse()->headers;
        $directives = array_map('trim', explode(',', (string) $headers->get('Cache-Control')));
        sort($directives);
        self::assertSame(['no-store', 'private'], $directives);
        self::assertFalse($headers->has('xkey'));
    }

    #[DataProvider('bothPaths')]
    public function testAValidCustomerTokenIsAccepted(string $path): void
    {
        $this->client->request('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::customer()]);

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bothPaths(): iterable
    {
        yield 'chunk list' => [self::CHUNKS];
        yield 'chunk' => [self::PRODUCTS.'?chunk=eyJmIjpudWxsLCJ0IjpudWxsfQ'];
    }

    public function testTheExportedSpecificationDescribesBothEndpoints(): void
    {
        $spec = $this->spec();
        $paths = self::arrayAt($spec, 'paths');

        $chunks = self::arrayAt($paths, self::CHUNKS, 'get');
        self::assertEqualsCanonicalizing(['200', '401', '503'], array_keys(self::arrayAt($chunks, 'responses')));
        self::assertSame('#/components/schemas/SitemapChunkList', self::arrayAt($chunks, 'responses', '200', 'content', 'application/json', 'schema')['$ref'] ?? null);

        $products = self::arrayAt($paths, self::PRODUCTS, 'get');
        self::assertEqualsCanonicalizing(['200', '400', '401', '503'], array_keys(self::arrayAt($products, 'responses')));
        self::assertSame('#/components/schemas/SitemapProductList', self::arrayAt($products, 'responses', '200', 'content', 'application/json', 'schema')['$ref'] ?? null);
        $parameter = self::arrayAt($products, 'parameters', '0');
        self::assertSame(['chunk', 'query', true], [$parameter['name'] ?? null, $parameter['in'] ?? null, $parameter['required'] ?? null]);
    }

    public function testTheResponsesMatchTheSchemasOfTheExportedSpecification(): void
    {
        $this->createProducts(2);

        $chunks = $this->get(self::CHUNKS);
        $this->assertKeysMatchSchema($chunks, 'SitemapChunkList');
        self::assertIsArray($chunks['chunks']);
        self::assertIsArray($chunks['chunks'][0]);
        $this->assertKeysMatchSchema($chunks['chunks'][0], 'SitemapChunk');

        $products = $this->get(self::PRODUCTS.'?chunk='.$this->firstChunkKey());
        $this->assertKeysMatchSchema($products, 'SitemapProductList');
        self::assertIsArray($products['items']);
        self::assertIsArray($products['items'][0]);
        $this->assertKeysMatchSchema($products['items'][0], 'SitemapProduct');
    }

    public function testTheAdminSpecificationDoesNotDescribeTheEndpoints(): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi-admin.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        $paths = self::arrayAt($spec, 'paths');

        self::assertArrayNotHasKey(self::CHUNKS, $paths);
        self::assertArrayNotHasKey(self::PRODUCTS, $paths);
    }

    private function firstChunkKey(): string
    {
        $chunks = $this->get(self::CHUNKS)['chunks'];
        self::assertIsArray($chunks);
        self::assertIsArray($chunks[0]);
        self::assertIsString($chunks[0]['key']);

        return $chunks[0]['key'];
    }

    private function createProducts(int $count): void
    {
        for ($number = 1; $number <= $count; ++$number) {
            $this->createProduct($number);
        }
    }

    private function createProduct(int $number, ProductStatus $status = ProductStatus::Published, ?\DateTimeImmutable $updatedAt = null): void
    {
        $product = ProductFactory::createOne([
            'id' => \sprintf('00000000-0000-4000-8000-%012d', $number),
            'slug' => 'product-'.$number,
            'mainCategory' => $this->category,
            'status' => $status,
        ]);
        $this->products->save($product, null, null, $updatedAt ?? new \DateTimeImmutable());
    }

    /**
     * @return array<mixed>
     */
    private function get(string $path): array
    {
        $this->client->request('GET', $path);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    /**
     * @return array<mixed>
     */
    private function spec(): array
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);

        return $spec;
    }

    /**
     * @param array<mixed> $object
     */
    private function assertKeysMatchSchema(array $object, string $schemaName): void
    {
        $schema = self::arrayAt($this->spec(), 'components', 'schemas', $schemaName);
        $properties = self::arrayAt($schema, 'properties');

        self::assertEqualsCanonicalizing(array_keys($properties), array_keys($object), $schemaName.' keys must match the schema.');
        self::assertEqualsCanonicalizing(self::arrayAt($schema, 'required'), array_keys($object), $schemaName.' requires every key.');
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private static function arrayAt(array $data, string ...$keys): array
    {
        $value = $data;
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }
        self::assertIsArray($value);

        return $value;
    }
}
