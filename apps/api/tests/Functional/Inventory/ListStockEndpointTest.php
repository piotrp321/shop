<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inventory;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Integration\Inventory\StockFixture;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /api/v1/admin/stock on PostgreSQL (shop_test, rolled back after each test).
 */
final class ListStockEndpointTest extends WebTestCase
{
    use Factories;
    use StockEndpointTestTrait {
        setUp as private setUpClient;
    }

    private Category $category;

    protected function setUp(): void
    {
        $this->setUpClient();
        $this->category = $this->saveCategory();
    }

    public function testListsProductsWithTheirVariantsInPositionOrderWithAndWithoutAStock(): void
    {
        $kayak = $this->saveProduct('Kajak', variants: [
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'values' => ['zielony']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'values' => ['pomarańczowy']],
        ], axes: ['Kolor']);
        $gel = $this->saveProduct('Żel malinowy');
        $gelVariant = $gel->variants()->toList()[0]->id()->toString();
        StockFixture::stock($this->connection, 10, 4, 3, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01');

        $body = $this->get('');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertNull($body['next']);
        $items = $this->items($body);
        self::assertCount(2, $items);
        self::assertSame(['productId' => $kayak->id()->toString(), 'name' => 'Kajak'], array_diff_key($items[0], ['variants' => 1]));
        $variants = $items[0]['variants'];
        self::assertIsArray($variants);
        self::assertIsArray($variants[1]);
        self::assertSame(
            [['variantId' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'label' => 'zielony', 'stock' => null], ['variantId' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'label' => 'pomarańczowy']],
            [$variants[0], array_diff_key($variants[1], ['stock' => 1])],
        );
        $stock = $variants[1]['stock'];
        self::assertIsArray($stock);
        self::assertSame(['physical' => 10, 'reserved' => 4, 'available' => 6, 'level' => 'in_stock', 'version' => 3], array_diff_key($stock, ['updatedAt' => 1]));
        self::assertUtcTimestamp($stock);
        self::assertNotSame($gel->id()->toString(), $gelVariant);
        self::assertSame(
            ['productId' => $gel->id()->toString(), 'name' => 'Żel malinowy', 'variants' => [['variantId' => $gelVariant, 'label' => '', 'stock' => null]]],
            $items[1],
        );
        $this->assertKeysMatchSchema($body, 'StockListResponse');
        $this->assertKeysMatchSchema($items[0], 'StockListItemResponse');
        $this->assertKeysMatchSchema($variants[1], 'StockListVariantResponse');
        $this->assertKeysMatchSchema($stock, 'ListedStockResponse');
    }

    public function testAPageCountsProductsEachWithAllItsVariants(): void
    {
        $this->saveProduct('A kajak', variants: [
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'values' => ['zielony']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'values' => ['niebieski']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03', 'values' => ['czerwony']],
        ], axes: ['Kolor']);
        $this->saveProduct('B bidon');

        $first = $this->get('perPage=1');
        $second = $this->get('perPage=1&after='.$this->next($first));

        self::assertSame(['A kajak'], array_column($this->items($first), 'name'));
        self::assertIsArray($this->items($first)[0]['variants']);
        self::assertCount(3, $this->items($first)[0]['variants']);
        self::assertSame(['B bidon'], array_column($this->items($second), 'name'));
        self::assertNull($second['next']);
    }

    public function testEmptyCatalogGivesAnEmptyList(): void
    {
        self::assertSame(['items' => [], 'next' => null], $this->get(''));
        self::assertResponseStatusCodeSame(200);
    }

    public function testDefaultPageHoldsTwentyFiveProductsAndTheCursorLeadsToTheRest(): void
    {
        for ($n = 1; $n <= 26; ++$n) {
            $this->saveProduct(\sprintf('Produkt %02d', $n));
        }

        $first = $this->get('');
        $second = $this->get('after='.$this->next($first));

        self::assertCount(25, $this->items($first));
        self::assertIsString($first['next']);
        self::assertSame(['Produkt 26'], array_column($this->items($second), 'name'));
        self::assertNull($second['next']);
    }

    public function testPagesWithPerPageAndSearchesByNameAndCategory(): void
    {
        $other = $this->saveCategory();
        foreach (['Bidon 1', 'Bidon 2', 'Bidon 3'] as $name) {
            $this->saveProduct($name);
        }
        $this->saveProduct('Bidon obcy', $other);
        $this->saveProduct('Plecak');
        $category = $this->category->id()->toString();

        $first = $this->get('q=bidon&category='.$category.'&perPage=2');
        $second = $this->get('q=bidon&category='.$category.'&perPage=2&after='.$this->next($first));

        self::assertSame(['Bidon 1', 'Bidon 2'], array_column($this->items($first), 'name'));
        self::assertSame(['Bidon 3'], array_column($this->items($second), 'name'));
        self::assertNull($second['next']);
    }

    public function testResponseIsPrivateAndNotStored(): void
    {
        $this->get('');

        $this->assertPrivateAndNotStored();
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
    public function testEveryStaffRoleCanReadTheStock(array $roles): void
    {
        $this->saveProduct('Buty');

        $this->get('', TestTokenFactory::staff($roles));

        self::assertResponseStatusCodeSame(200);
    }

    public function testWithoutATokenTheRequestIsUnauthorized(): void
    {
        $this->client->request('GET', '/api/v1/admin/stock');

        $this->assertUnauthorized();
    }

    public function testATokenOfTheShopRealmIsUnauthorized(): void
    {
        $this->get('', TestTokenFactory::customer());

        $this->assertUnauthorized();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedQueries(): iterable
    {
        yield 'one character' => ['q=a'];
        yield 'empty term' => ['q='];
        yield 'term over 100 characters' => ['q='.str_repeat('a', 101)];
        yield 'term with a NUL character' => ['q=a%00zzzz'];
        yield 'zero per page' => ['perPage=0'];
        yield 'per page over 100' => ['perPage=101'];
        yield 'negative per page' => ['perPage=-1'];
        yield 'per page not a number' => ['perPage=many'];
        yield 'category not a uuid' => ['category=shoes'];
        yield 'empty category' => ['category='];
        yield 'malformed cursor' => ['after=garbage!'];
        yield 'cursor of another shape' => ['after=aGVsbG8'];
        yield 'empty cursor' => ['after='];
        yield 'term as an array' => ['q[]=abc'];
    }

    #[DataProvider('rejectedQueries')]
    public function testBadParametersAreRejectedWithProblemDetails(string $query): void
    {
        $this->get($query);

        $this->assertProblem(400);
    }

    public function testBoundariesOfTheParametersAreAccepted(): void
    {
        $this->saveProduct('Buty');

        foreach (['q=Bu', 'q='.str_repeat('a', 100), 'perPage=1', 'perPage=100'] as $query) {
            $this->get($query);

            self::assertResponseStatusCodeSame(200, $query);
        }
    }

    public function testAnUnknownCategoryIsNotFound(): void
    {
        $this->get('category=0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60');

        $this->assertProblem(404);
    }

    private function saveCategory(): Category
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);

        return $category;
    }

    /**
     * @param list<array{id: string, values: list<string>}> $variants none for the factory's default variant
     * @param list<string>                                  $axes
     */
    private function saveProduct(string $name, ?Category $category = null, array $variants = [], array $axes = []): Product
    {
        $attributes = ['mainCategory' => $category ?? $this->category, 'name' => $name];
        if ([] !== $variants) {
            $attributes += ['variantAxes' => $axes, 'variants' => $variants];
        }
        $product = ProductFactory::createOne($attributes);
        new DoctrineProductRepository($this->entityManager)->save($product);

        return $product;
    }

    /**
     * Requests the list as a staff member (an administrator unless the token says otherwise).
     *
     * @return array<mixed>
     */
    private function get(string $query, ?string $token = null): array
    {
        $this->client->request('GET', '/api/v1/admin/stock'.('' === $query ? '' : '?'.$query), server: ['HTTP_AUTHORIZATION' => 'Bearer '.($token ?? TestTokenFactory::staff(['admin']))]);

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
