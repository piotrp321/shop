<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

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
 * GET /api/v1/products/{slug} on PostgreSQL (shop_test, rolled back after each test).
 */
final class ProductDetailsEndpointTest extends WebTestCase
{
    use Factories;

    private const string PRODUCT_ID = '0192f0c4-7c1a-7c4e-9a6e-000000000010';
    private const string BLACK_42 = 'c0000000-0000-4000-8000-000000000001';
    private const string WHITE_43 = 'a0000000-0000-4000-8000-000000000002';

    private KernelBrowser $client;
    /** @var list<string> */
    private array $categoryIds = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $categories = new DoctrineCategoryRepository($entityManager);
        $sport = CategoryFactory::createOne(['name' => 'Sport', 'slug' => 'sport']);
        $categories->save($sport);
        $shoes = CategoryFactory::createOne(['name' => 'Obuwie', 'slug' => 'obuwie', 'parent' => $sport]);
        $categories->save($shoes);
        $product = ProductFactory::createOne([
            'id' => self::PRODUCT_ID,
            'name' => 'Buty szosowe Asfalt',
            'slug' => 'buty-szosowe-asfalt',
            'shortDescription' => 'Lekkie buty na asfalt.',
            'mainCategory' => $shoes,
            'imageAlts' => ['Pierwsze', 'Drugie'],
            'variantAxes' => ['Kolor', 'Rozmiar'],
            'variants' => [
                ['id' => self::BLACK_42, 'values' => ['czarne', '42']],
                ['id' => self::WHITE_43, 'values' => ['białe', '43']],
            ],
        ]);
        new DoctrineProductRepository($entityManager)->save($product, null, null, new \DateTimeImmutable());
        $this->categoryIds = [$sport->id()->toString(), $shoes->id()->toString()];
    }

    public function testTheBodyIsTheProductDetails(): void
    {
        $body = $this->get('/api/v1/products/buty-szosowe-asfalt');

        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(['id', 'slug', 'name', 'shortDescription', 'breadcrumb', 'images', 'variantAxes', 'variants'], array_keys($body));
        self::assertSame(self::PRODUCT_ID, $body['id']);
        self::assertSame('buty-szosowe-asfalt', $body['slug']);
        self::assertSame('Buty szosowe Asfalt', $body['name']);
        self::assertSame('Lekkie buty na asfalt.', $body['shortDescription']);
        self::assertSame([
            ['id' => $this->categoryIds[0], 'name' => 'Sport', 'path' => 'sport'],
            ['id' => $this->categoryIds[1], 'name' => 'Obuwie', 'path' => 'sport/obuwie'],
        ], $body['breadcrumb']);
        self::assertSame(['Kolor', 'Rozmiar'], $body['variantAxes']);
        self::assertSame(
            [
                ['variantId' => self::BLACK_42, 'label' => 'czarne, 42', 'values' => ['czarne', '42']],
                ['variantId' => self::WHITE_43, 'label' => 'białe, 43', 'values' => ['białe', '43']],
            ],
            $body['variants'],
        );
        self::assertIsArray($body['images']);
        self::assertSame(['Pierwsze', 'Drugie'], array_column($body['images'], 'alt'));
        $first = $body['images'][0];
        self::assertIsArray($first);
        self::assertSame(['alt', 'size', 'aspectRatio', 'variants'], array_keys($first));
        self::assertSame('product', $first['size']);
        self::assertIsArray($first['variants']);
        self::assertIsArray($first['variants']['webp']);
        self::assertSame([640, 960, 1280, 1600], array_column($first['variants']['webp'], 'width'));

        $this->assertKeysMatchSchema($body, 'ProductDetails');
        $this->assertKeysMatchSchema($body['breadcrumb'][0], 'BreadcrumbItem');
        $this->assertKeysMatchSchema($body['variants'][0], 'ProductDetailsVariant');
        $this->assertKeysMatchSchema($first, 'ProductImage');
    }

    public function testTheOkResponseIsCachedByTheBrowserAndByVarnishAndTagged(): void
    {
        $this->get('/api/v1/products/buty-szosowe-asfalt');

        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('public'));
        self::assertSame('60', $headers->getCacheControlDirective('max-age'));
        self::assertSame('3600', $headers->getCacheControlDirective('s-maxage'));
        self::assertFalse($headers->hasCacheControlDirective('no-store'));
        self::assertSame('catalog product-'.self::PRODUCT_ID, $headers->get('xkey'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingProducts(): iterable
    {
        yield 'unknown slug' => ['/api/v1/products/nie-ma-takiego'];
        yield 'slug in a wrong case' => ['/api/v1/products/Buty-Szosowe-Asfalt'];
        yield 'malformed slug with a dash at the end' => ['/api/v1/products/buty-'];
        yield 'malformed slug with a space' => ['/api/v1/products/buty%20szosowe'];
        yield 'malformed slug with a dot' => ['/api/v1/products/buty.szosowe'];
        yield 'slug too long' => ['/api/v1/products/'.str_repeat('a', 201)];
    }

    #[DataProvider('missingProducts')]
    public function testAnUnknownOrMalformedSlugIsNotFound(string $path): void
    {
        $problem = $this->get($path);

        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(404, $problem['status']);
        self::assertSame('Produkt nie istnieje.', $problem['detail']);
        self::assertSame('Not Found', $problem['title']);
        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('public'));
        self::assertSame('60', $headers->getCacheControlDirective('max-age'));
        self::assertFalse($headers->hasCacheControlDirective('s-maxage'));
        self::assertSame('catalog', $headers->get('xkey'));
    }

    public function testAnInvalidTokenIsUnauthorizedAndNotStored(): void
    {
        $this->client->request('GET', '/api/v1/products/buty-szosowe-asfalt', server: ['HTTP_AUTHORIZATION' => 'Bearer garbage']);

        self::assertResponseStatusCodeSame(401);
        $headers = $this->client->getResponse()->headers;
        $directives = array_map('trim', explode(',', (string) $headers->get('Cache-Control')));
        sort($directives);
        self::assertSame(['no-store', 'private'], $directives);
        self::assertFalse($headers->has('xkey'));
    }

    public function testOnlyTheReadOfOneSlugIsARoute(): void
    {
        $this->client->request('POST', '/api/v1/products/buty-szosowe-asfalt');
        self::assertResponseStatusCodeSame(405);

        $this->client->request('GET', '/api/v1/products/buty-szosowe-asfalt/images');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAValidCustomerTokenIsAccepted(): void
    {
        $this->client->request('GET', '/api/v1/products/buty-szosowe-asfalt', server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::customer()]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testExportedSpecificationDescribesTheEndpoint(): void
    {
        $spec = $this->spec();
        self::assertIsArray($spec['paths']);
        self::assertArrayHasKey('/api/v1/products/{slug}', $spec['paths']);
        $path = $spec['paths']['/api/v1/products/{slug}'];
        self::assertIsArray($path);
        $operation = $path['get'];
        self::assertIsArray($operation);
        self::assertIsArray($operation['responses']);
        self::assertEqualsCanonicalizing(['200', '401', '404', '503'], array_keys($operation['responses']));
        $notFound = $operation['responses']['404'];
        self::assertIsArray($notFound);
        self::assertIsArray($notFound['content']);
        self::assertSame(['application/problem+json'], array_keys($notFound['content']));
    }

    public function testTheAdminSpecificationDoesNotDescribeTheEndpoint(): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi-admin.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['paths']);
        self::assertArrayNotHasKey('/api/v1/products/{slug}', $spec['paths']);
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
        $spec = $this->spec();
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        $schema = $spec['components']['schemas'][$schemaName] ?? null;
        self::assertIsArray($schema, $schemaName.' must be in the exported specification.');
        self::assertIsArray($schema['properties']);
        self::assertIsArray($schema['required']);

        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($object), $schemaName.' keys must match the schema.');
        self::assertEqualsCanonicalizing($schema['required'], array_keys($object), $schemaName.' requires every key.');
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
}
