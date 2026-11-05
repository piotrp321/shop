<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cart;

use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /api/v1/product-offers on PostgreSQL (shop_test, rolled back after each test), with products of Catalog, prices
 * of Pricing and stock of Inventory. The id list is sent as a repeated `productId` key, as the generated client of the
 * store does. Every variant id differs from the id of its product.
 */
final class ProductOffersEndpointTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    public function testTheOffersOfProductsWithoutAToken(): void
    {
        // 42: purchasable; 43: priced at an outdated VAT rate; 44: cheaper but none in stock; 45: purchasable, low stock.
        [$shoes, [$v42, $v43, $v44, $v45]] = $this->product(['Kolor', 'Rozmiar'], [
            [['czarne', '42'], 30_000, 2300, 20],
            [['czarne', '43'], 20_000, 800, 20],
            [['czarne', '44'], 25_000, 2300, 0],
            [['czarne', '45'], 32_000, 2300, 3],
        ]);
        [$dumbbells, [$default]] = $this->product([], [[[], 19_900, 2300, 40]]);

        $body = $this->get([$shoes, $dumbbells]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['items' => [
            [
                'productId' => $shoes,
                'variantAxes' => ['Kolor', 'Rozmiar'],
                'fromGross' => 30_000,
                'hasPriceRange' => true,
                'currency' => 'PLN',
                'level' => 'in_stock',
                'variants' => [
                    ['variantId' => $v42, 'label' => 'czarne, 42', 'gross' => 30_000, 'level' => 'in_stock', 'purchasable' => true],
                    ['variantId' => $v43, 'label' => 'czarne, 43', 'gross' => null, 'level' => 'in_stock', 'purchasable' => false],
                    ['variantId' => $v44, 'label' => 'czarne, 44', 'gross' => 25_000, 'level' => 'out_of_stock', 'purchasable' => false],
                    ['variantId' => $v45, 'label' => 'czarne, 45', 'gross' => 32_000, 'level' => 'low_stock', 'purchasable' => true],
                ],
            ],
            [
                'productId' => $dumbbells,
                'variantAxes' => [],
                'fromGross' => 19_900,
                'hasPriceRange' => false,
                'currency' => 'PLN',
                'level' => 'in_stock',
                'variants' => [['variantId' => $default, 'label' => '', 'gross' => 19_900, 'level' => 'in_stock', 'purchasable' => true]],
            ],
        ]], $body);
        self::assertNotSame($dumbbells, $default);
        $this->assertKeysMatchSchema($body, 'ProductOfferListResponse');
        $this->assertKeysMatchSchema($body['items'][0], 'ProductOfferResponse');
        $this->assertKeysMatchSchema($body['items'][0]['variants'][0], 'VariantOfferResponse');
    }

    public function testTheNumberOfUnitsIsNeverReturned(): void
    {
        [$id] = $this->product([], [[[], 1000, 2300, 7357]]);

        $this->get([$id]);

        self::assertStringNotContainsString('7357', (string) $this->client->getResponse()->getContent());
    }

    public function testResponseIsPubliclyCachedForSixtySeconds(): void
    {
        [$id] = $this->product([], [[[], 1000, 2300, 5]]);

        $this->get([$id]);

        self::assertSame('max-age=60, public', $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testEveryRequestedIdHasOneOfferInTheOrderOfTheRequest(): void
    {
        [$kayak, [$green]] = $this->product(['Kolor'], [[['zielony'], 150_000, 2300, 0]]);
        [$priced] = $this->product([], [[[], 1000, 2300, 5]]);
        $unknown = (string) Uuid::v7();

        $body = $this->get([$kayak, $unknown, $priced, $kayak]);

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($body['items']);
        self::assertSame([$kayak, $unknown, $priced], array_column($body['items'], 'productId'));
        self::assertSame(
            ['productId' => $kayak, 'variantAxes' => ['Kolor'], 'fromGross' => null, 'hasPriceRange' => false, 'currency' => 'PLN', 'level' => 'out_of_stock', 'variants' => [['variantId' => $green, 'label' => 'zielony', 'gross' => 150_000, 'level' => 'out_of_stock', 'purchasable' => false]]],
            $body['items'][0],
        );
        self::assertSame(
            ['productId' => $unknown, 'variantAxes' => [], 'fromGross' => null, 'hasPriceRange' => false, 'currency' => 'PLN', 'level' => 'out_of_stock', 'variants' => []],
            $body['items'][1],
        );
    }

    public function testAVariantIdIsNoProduct(): void
    {
        [, [$variant]] = $this->product([], [[[], 1000, 2300, 5]]);

        $body = $this->get([$variant]);

        self::assertIsArray($body['items']);
        self::assertIsArray($body['items'][0]);
        self::assertSame([], $body['items'][0]['variants']);
        self::assertNull($body['items'][0]['fromGross']);
    }

    public function testAcceptsFortyEightIds(): void
    {
        [$first] = $this->product([], [[[], 1000, 2300, 5]]);

        $body = $this->get([$first, ...array_map(static fn (): string => (string) Uuid::v7(), range(1, 47))]);

        self::assertResponseStatusCodeSame(200);
        self::assertIsArray($body['items']);
        self::assertCount(48, $body['items']);
    }

    public function testAnInvalidTokenIsRefusedEvenThoughNoneIsNeeded(): void
    {
        $this->client->request('GET', '/api/v1/product-offers?productId='.Uuid::v7(), server: ['HTTP_AUTHORIZATION' => 'Bearer not-a-token']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testACustomerTokenIsAccepted(): void
    {
        [$id] = $this->product([], [[[], 1000, 2300, 5]]);

        $this->client->request('GET', '/api/v1/product-offers?productId='.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::customer()]);

        self::assertResponseStatusCodeSame(200);
    }

    #[DataProvider('rejectedRequests')]
    public function testRejectsABadIdListWithProblemDetails(string $query): void
    {
        $this->client->request('GET', '/api/v1/product-offers'.$query);

        self::assertResponseStatusCodeSame(400);
        $response = $this->client->getResponse();
        self::assertStringContainsString('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertFalse($response->headers->hasCacheControlDirective('public'));
        $problem = $this->json();
        self::assertSame(400, $problem['status']);
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
     * A product of Catalog with the given axes and variants: values, gross price, VAT rate of the price in basis
     * points (2300 is the current rate of the standard class) and physical stock.
     *
     * @param list<string>                             $axes
     * @param list<array{list<string>, int, int, int}> $variants
     *
     * @return array{string, list<string>} the id of the product and the ids of its variants
     */
    private function product(array $axes, array $variants): array
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $definitions = array_map(static fn (array $variant): array => ['id' => (string) Uuid::v4(), 'values' => $variant[0]], $variants);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'variantAxes' => $axes, 'variants' => $definitions]);
        new DoctrineProductRepository($this->entityManager)->save($product);
        foreach ($variants as $index => [, $gross, $basisPoints, $stock]) {
            VariantPriceRecordFactory::store($this->entityManager, ['variantId' => $definitions[$index]['id'], 'gross' => $gross, 'taxClass' => 'standard', 'vatRateBp' => $basisPoints]);
            StockFixture::stock($this->entityManager->getConnection(), $stock, variantId: $definitions[$index]['id']);
        }

        return [$product->id()->toString(), array_column($definitions, 'id')];
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
        $this->client->request('GET', '/api/v1/product-offers?'.implode('&', array_map(static fn (string $id): string => 'productId='.$id, $ids)));

        return $this->json();
    }

    /** @return array<mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
