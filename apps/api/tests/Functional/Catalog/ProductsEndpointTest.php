<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\Port\ProductSearch;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\SearchResult;
use App\Catalog\Application\Search\SearchUnavailable;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\Search\ElasticsearchProductSearch;
use App\Tests\Factory\ProductDocumentFactory;
use App\Tests\Integration\Catalog\Search\UsesTemporarySearchAlias;
use App\Tests\Support\SwitchableProductSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Runs on a temporary index of the real Elasticsearch (see UsesTemporarySearchAlias).
 * Category tree: ROOT > CHILD > LEAF, and a separate OTHER.
 */
final class ProductsEndpointTest extends WebTestCase
{
    use Factories;
    use UsesTemporarySearchAlias;

    private const string ROOT = '00000000-0000-4000-8000-0000000000a1';
    private const string CHILD = '00000000-0000-4000-8000-0000000000a2';
    private const string LEAF = '00000000-0000-4000-8000-0000000000a3';
    private const string OTHER = '00000000-0000-4000-8000-0000000000b1';
    private const string UNKNOWN = '00000000-0000-4000-8000-0000000000ff';
    private const string IMAGE_KEY = 'originals/0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef.jpg';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The container must survive between requests, the replaced search service lives in it.
        $this->client->disableReboot();
        $this->maintenance()->setup();
        $this->searchService()->use(new ElasticsearchProductSearch(self::clientFactory()->create(2.0), $this->alias()));
    }

    public function testResponseHasTheDocumentedShape(): void
    {
        $this->index(
            $this->document('Buty', self::LEAF, [self::ROOT, self::CHILD, self::LEAF], ['key' => self::IMAGE_KEY, 'alt' => 'Biegaczka na leśnej ścieżce']),
            $this->document('Czapka', self::OTHER, [self::OTHER]),
        );

        $body = $this->get('/api/v1/products');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(['items', 'page', 'perPage', 'total'], array_keys($body));
        self::assertSame(1, $body['page']);
        self::assertSame(24, $body['perPage']);
        self::assertSame(2, $body['total']);

        $items = $this->items($body);
        self::assertSame(['Buty', 'Czapka'], array_column($items, 'name'));
        self::assertSame(['id', 'name', 'slug', 'shortDescription', 'image'], array_keys($items[0]));
        self::assertSame('slug-buty', $items[0]['slug']);
        self::assertNull($items[1]['image']);
        $this->assertKeysMatchSchema($body, 'ProductPage');
        $this->assertKeysMatchSchema($items[0], 'ProductListItem');
        $this->assertKeysMatchSchema($items[1], 'ProductListItem');

        $image = $items[0]['image'];
        self::assertIsArray($image);
        self::assertSame(['alt', 'size', 'aspectRatio', 'variants'], array_keys($image));
        $this->assertKeysMatchSchema($image, 'ProductImage');
        self::assertSame('Biegaczka na leśnej ścieżce', $image['alt']);
        self::assertSame('listing', $image['size']);
        self::assertSame('1:1', $image['aspectRatio']);
        self::assertIsArray($image['variants']);
        self::assertSame(['webp'], array_keys($image['variants']));
        self::assertIsArray($image['variants']['webp']);
        self::assertSame([256, 384, 512, 768], array_column($image['variants']['webp'], 'width'));
        $imgproxy = $_SERVER['IMGPROXY_PUBLIC_URL'] ?? getenv('IMGPROXY_PUBLIC_URL');
        self::assertIsString($imgproxy);
        foreach (array_column($image['variants']['webp'], 'url') as $url) {
            self::assertIsString($url);
            self::assertStringStartsWith(rtrim($imgproxy, '/').'/', $url);
            self::assertStringEndsWith('.webp', $url);
        }
    }

    public function testTopLevelCategoryFilterIncludesProductsOfSubcategories(): void
    {
        $this->index(
            $this->document('Root', self::ROOT, [self::ROOT]),
            $this->document('Child', self::CHILD, [self::ROOT, self::CHILD]),
            $this->document('Leaf', self::LEAF, [self::ROOT, self::CHILD, self::LEAF]),
            $this->document('Other', self::OTHER, [self::OTHER]),
        );

        $body = $this->get('/api/v1/products?category='.self::ROOT);

        self::assertSame(['Child', 'Leaf', 'Root'], array_column($this->items($body), 'name'));
        self::assertSame(3, $body['total']);
        self::assertSame(['Leaf'], array_column($this->items($this->get('/api/v1/products?category='.self::LEAF)), 'name'));
    }

    public function testUnknownCategoryGivesAnEmptyList(): void
    {
        $this->index($this->document('Buty', self::LEAF, [self::LEAF]));

        $body = $this->get('/api/v1/products?category='.self::UNKNOWN);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $body['items']);
        self::assertSame(0, $body['total']);
    }

    public function testPagePastTheEndGivesAnEmptyListWithTheRealTotal(): void
    {
        $this->index($this->document('A', self::LEAF, [self::LEAF]), $this->document('B', self::LEAF, [self::LEAF]), $this->document('C', self::LEAF, [self::LEAF]));

        $body = $this->get('/api/v1/products?page=999');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $body['items']);
        self::assertSame(3, $body['total']);
        self::assertSame(999, $body['page']);
        self::assertSame(24, $body['perPage']);
    }

    public function testPaginates(): void
    {
        $this->index($this->document('A', self::LEAF, [self::LEAF]), $this->document('B', self::LEAF, [self::LEAF]), $this->document('C', self::LEAF, [self::LEAF]));

        $body = $this->get('/api/v1/products?page=2&perPage=2');

        self::assertSame(['C'], array_column($this->items($body), 'name'));
        self::assertSame(2, $body['page']);
        self::assertSame(2, $body['perPage']);
        self::assertSame(3, $body['total']);
    }

    public function testPerPageUpTo48IsAccepted(): void
    {
        $this->get('/api/v1/products?perPage=48');

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidQueries(): iterable
    {
        yield 'category is not a UUID' => ['category=abc', 'category'];
        yield 'category is empty' => ['category=', 'category'];
        yield 'perPage is zero' => ['perPage=0', 'perPage'];
        yield 'perPage over the maximum' => ['perPage=49', 'perPage'];
        yield 'perPage is negative' => ['perPage=-5', 'perPage'];
        yield 'perPage is not a number' => ['perPage=lots', 'perPage'];
        yield 'page is zero' => ['page=0', 'page'];
        yield 'page is not a number' => ['page=first', 'page'];
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidParametersGiveProblemDetailsWithViolations(string $query, string $property): void
    {
        $this->index($this->document('Buty', self::LEAF, [self::LEAF]));

        $problem = $this->get('/api/v1/products?'.$query);

        self::assertResponseStatusCodeSame(400);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(400, $problem['status']);
        self::assertIsArray($problem['violations']);
        $violation = $problem['violations'][0];
        self::assertIsArray($violation);
        self::assertSame($property, $violation['propertyPath']);
        self::assertIsString($violation['title']);
        $value = explode('=', $query, 2)[1];
        if (\strlen($value) >= 3) {
            self::assertStringNotContainsString($value, (string) $this->client->getResponse()->getContent());
        }
    }

    public function testResponseIsPubliclyCachedAndVariesOnOrigin(): void
    {
        $this->get('/api/v1/products');

        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('public'));
        self::assertSame('60', $headers->getCacheControlDirective('max-age'));
        self::assertStringContainsString('Origin', (string) $headers->get('Vary'));
        self::assertSame('max-age=60, public', $headers->get('Cache-Control'));
    }

    public function testUnavailableSearchGivesProblemDetailsWithoutTechnicalDetails(): void
    {
        $this->searchService()->use($this->failingSearch(new SearchUnavailable('Elasticsearch at http://elasticsearch:9200 refused the connection.')));

        $problem = $this->get('/api/v1/products');

        self::assertResponseStatusCodeSame(503);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(503, $problem['status']);
        self::assertSame('Service Unavailable', $problem['title']);
        self::assertArrayNotHasKey('detail', $problem);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('elasticsearch', strtolower($content));
        self::assertStringNotContainsString('refused', $content);
        self::assertFalse($this->client->getResponse()->headers->hasCacheControlDirective('public'));
    }

    public function testPermanentSearchFailureIsAnOpaque500(): void
    {
        $this->searchService()->use($this->failingSearch(new SearchIndexError('The alias products is missing.')));

        $problem = $this->get('/api/v1/products');

        self::assertResponseStatusCodeSame(500);
        self::assertArrayNotHasKey('detail', $problem);
        self::assertStringNotContainsString('alias', (string) $this->client->getResponse()->getContent());
    }

    public function testWrongMethodReturns405Problem(): void
    {
        $this->client->request('POST', '/api/v1/products');

        self::assertResponseStatusCodeSame(405);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testExportedSpecificationDescribesTheEndpoint(): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['paths']);

        self::assertArrayHasKey('/api/v1/products', $spec['paths']);
        self::assertArrayNotHasKey('/health', $spec['paths']);
        self::assertSame([], array_filter(array_keys($spec['paths']), static fn (string $path): bool => str_starts_with($path, '/api/v1/admin')));
        $path = $spec['paths']['/api/v1/products'];
        self::assertIsArray($path);
        $operation = $path['get'];
        self::assertIsArray($operation);
        self::assertIsArray($operation['responses']);
        self::assertEqualsCanonicalizing(['200', '400', '401', '503'], array_keys($operation['responses']));
        foreach (['400', '401', '503'] as $status) {
            $response = $operation['responses'][$status];
            self::assertIsArray($response);
            self::assertIsArray($response['content']);
            self::assertSame(['application/problem+json'], array_keys($response['content']));
            self::assertSame(['schema' => ['$ref' => '#/components/schemas/Problem']], $response['content']['application/problem+json']);
        }
        $ok = $operation['responses']['200'];
        self::assertIsArray($ok);
        self::assertIsArray($ok['content']);
        self::assertSame(['application/json'], array_keys($ok['content']));
        self::assertIsArray($operation['parameters']);
        self::assertEqualsCanonicalizing(['category', 'page', 'perPage'], array_column($operation['parameters'], 'name'));
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        foreach (['ProductPage', 'ProductListItem', 'ProductImage', 'Problem'] as $schema) {
            self::assertArrayHasKey($schema, $spec['components']['schemas']);
        }
    }

    /**
     * The response object has exactly the properties of the exported schema, and every required one is present.
     *
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

    private function searchService(): SwitchableProductSearch
    {
        $service = self::getContainer()->get(SwitchableProductSearch::class);
        self::assertInstanceOf(SwitchableProductSearch::class, $service);

        return $service;
    }

    private function failingSearch(\Throwable $failure): ProductSearch
    {
        return new readonly class($failure) implements ProductSearch {
            public function __construct(private \Throwable $failure)
            {
            }

            public function search(?string $categoryId, int $page, int $perPage): SearchResult
            {
                throw $this->failure;
            }
        };
    }

    /**
     * @param list<string>                         $ancestors
     * @param array{key: string, alt: string}|null $image
     */
    private function document(string $name, string $main, array $ancestors, ?array $image = null): ProductDocument
    {
        return ProductDocumentFactory::createOne([
            'name' => $name,
            'slug' => 'slug-'.strtolower($name),
            'mainCategoryId' => $main,
            'categoryIds' => [$main],
            'categoryAncestorIds' => $ancestors,
            'mainImage' => $image,
        ]);
    }

    private function index(ProductDocument ...$documents): void
    {
        $indexer = new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias());
        foreach ($documents as $document) {
            $indexer->index($document);
        }
        self::esClient()->indices()->refresh(['index' => $this->alias()]);
    }

    /**
     * @return array<mixed>
     */
    private function get(string $uri): array
    {
        $this->client->request('GET', $uri);
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
        /** @var list<array<string, mixed>> $items */
        $items = $body['items'];

        return $items;
    }
}
