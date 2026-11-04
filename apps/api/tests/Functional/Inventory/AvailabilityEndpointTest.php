<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Catalog\Domain\Product\Product;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /api/v1/availability on PostgreSQL (shop_test, rolled back after each test). The id list is sent as a repeated
 * `productId` key, as the generated client of the store does.
 *
 * Transitional until the product offers replace the endpoint (VARIANTS-4): stock is kept per variant, a product with
 * exactly one variant has the level of that variant and a product with more variants is out of stock.
 */
final class AvailabilityEndpointTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
    }

    public function testAnswersTheLevelOfEachRequestedProductWithoutAToken(): void
    {
        $out = $this->stockedProduct(0);
        $lowFloor = $this->stockedProduct(1);
        $lowCeiling = $this->stockedProduct(5);
        $inStock = $this->stockedProduct(6);
        $reservedDown = $this->stockedProduct(10, 5);

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
        $inStock = $this->stockedProduct(20);
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

    public function testAProductWithMoreThanOneVariantIsOutOfStockEvenWhenItsVariantsAreInStock(): void
    {
        $single = $this->stockedProduct(20);
        $twoVariants = $this->productWithVariants(2, 30);

        $body = $this->get([$twoVariants, $single]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['items' => [
                ['productId' => $twoVariants, 'level' => 'out_of_stock'],
                ['productId' => $single, 'level' => 'in_stock'],
            ]],
            $body,
        );
    }

    public function testAVariantIdIsNotAProduct(): void
    {
        $productId = $this->stockedProduct(20);
        $variantId = $this->connection->fetchOne('SELECT id FROM catalog_product_variant WHERE product_id = ?', [$productId]);
        self::assertIsString($variantId);
        self::assertNotSame($productId, $variantId);

        self::assertSame(['items' => [['productId' => $variantId, 'level' => 'out_of_stock']]], $this->get([$variantId]));
    }

    public function testNeverReturnsQuantities(): void
    {
        $productId = $this->stockedProduct(7, 2);

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
        $first = $this->stockedProduct(3);
        $second = $this->stockedProduct(30);

        // Written by hand, so the test does not depend on how PHP or Symfony would rebuild the query string.
        $this->client->request('GET', '/api/v1/availability?productId='.$first.'&productId='.rawurlencode($second).'&productId='.$first);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$first, $second], array_column($this->items($this->json()), 'productId'));
    }

    public function testResponseIsPubliclyCachedForSixtySeconds(): void
    {
        $this->get([$this->stockedProduct(3)]);

        $headers = $this->client->getResponse()->headers;
        self::assertTrue($headers->hasCacheControlDirective('public'));
        self::assertSame('60', $headers->getCacheControlDirective('max-age'));
        self::assertSame('max-age=60, public', $headers->get('Cache-Control'));
    }

    public function testAcceptsFortyEightIds(): void
    {
        $first = $this->stockedProduct(3);
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
     * @return string the id of a Catalog product with one default variant (never of the id of the product) and the
     *                stock of that variant
     */
    private function stockedProduct(int $physical, int $reserved = 0): string
    {
        $product = $this->saveProduct([]);
        StockFixture::stock($this->connection, $physical, $reserved, variantId: $product->variants()->toList()[0]->id()->toString());

        return $product->id()->toString();
    }

    /**
     * @return string the id of a product with the given number of variants, each with the stock
     */
    private function productWithVariants(int $count, int $physical): string
    {
        $product = $this->saveProduct(array_map(static fn (int $n): array => ['id' => (string) Uuid::v4(), 'values' => ['kolor '.$n]], range(1, $count)));
        foreach ($product->variants()->toList() as $variant) {
            StockFixture::stock($this->connection, $physical, variantId: $variant->id()->toString());
        }

        return $product->id()->toString();
    }

    /**
     * @param list<array{id: string, values: list<string>}> $variants none for one default variant
     */
    private function saveProduct(array $variants): Product
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $attributes = ['mainCategory' => $category];
        if ([] !== $variants) {
            $attributes += ['variantAxes' => ['Kolor'], 'variants' => $variants];
        }
        $product = ProductFactory::createOne($attributes);
        new DoctrineProductRepository($this->entityManager)->save($product);

        return $product;
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
