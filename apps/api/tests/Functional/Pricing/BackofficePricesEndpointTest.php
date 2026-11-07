<?php

declare(strict_types=1);

namespace App\Tests\Functional\Pricing;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /api/v1/admin/prices on PostgreSQL (shop_test, rolled back after each test).
 */
final class BackofficePricesEndpointTest extends WebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private Category $category;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->category = $this->saveCategory();
    }

    public function testListsProductsWithTheirVariantsInPositionOrderWithAndWithoutAPrice(): void
    {
        $book = $this->saveProduct('Przewodnik Tatry', TaxClassCode::PrintedBook, variants: [
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'values' => ['oprawa twarda']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'values' => ['oprawa miękka']],
        ], axes: ['Oprawa']);
        $gel = $this->saveProduct('Żel malinowy', TaxClassCode::EnergyGel);
        $gelVariant = $this->defaultVariant($gel);
        VariantPriceRecordFactory::store($this->entityManager, ['variantId' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'gross' => 5490, 'taxClass' => 'printed_book', 'vatRateBp' => 500]);

        $body = $this->get('');

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('application/json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertNull($body['next']);
        $items = $this->items($body);
        self::assertCount(2, $items);
        self::assertSame(
            ['productId' => $book->id()->toString(), 'name' => 'Przewodnik Tatry', 'taxClass' => 'printed_book', 'applicableVatRate' => 500],
            array_diff_key($items[0], ['variants' => 1]),
        );
        $variants = $items[0]['variants'];
        self::assertIsArray($variants);
        self::assertIsArray($variants[0]);
        self::assertIsArray($variants[1]);
        self::assertSame(
            [['variantId' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'label' => 'oprawa twarda', 'price' => null], ['variantId' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'label' => 'oprawa miękka']],
            [$variants[0], array_diff_key($variants[1], ['price' => 1])],
        );
        $price = $variants[1]['price'];
        self::assertIsArray($price);
        self::assertSame(
            ['gross' => 5490, 'net' => 5229, 'vat' => 261, 'vatRate' => 500, 'currency' => 'PLN', 'version' => 1, 'vatRateOutdated' => false],
            array_diff_key($price, ['updatedAt' => 1]),
        );
        self::assertIsString($price['updatedAt']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $price['updatedAt']);
        self::assertNotSame($gel->id()->toString(), $gelVariant);
        self::assertSame(
            ['productId' => $gel->id()->toString(), 'name' => 'Żel malinowy', 'taxClass' => 'energy_gel', 'applicableVatRate' => 800, 'variants' => [['variantId' => $gelVariant, 'label' => '', 'price' => null]]],
            $items[1],
        );
        $this->assertKeysMatchSchema($body, 'PriceListResponse');
        $this->assertKeysMatchSchema($items[0], 'PriceListItemResponse');
        $this->assertKeysMatchSchema($variants[1], 'PriceListVariantResponse');
        $this->assertKeysMatchSchema($price, 'ListedPriceResponse');
    }

    public function testAPriceAtAnOutdatedRateIsMarkedAndTheCurrentRateIsApplicable(): void
    {
        $shoes = $this->saveProduct('Buty', TaxClassCode::Standard);
        VariantPriceRecordFactory::store($this->entityManager, ['variantId' => $this->defaultVariant($shoes), 'gross' => 10800, 'taxClass' => 'standard', 'vatRateBp' => 800]);

        $item = $this->items($this->get(''))[0];

        self::assertSame(2300, $item['applicableVatRate']);
        self::assertIsArray($item['variants']);
        self::assertIsArray($item['variants'][0]);
        $price = $item['variants'][0]['price'];
        self::assertIsArray($price);
        self::assertSame(800, $price['vatRate']);
        self::assertTrue($price['vatRateOutdated']);
    }

    /**
     * The page size counts products: every product of a page comes with all its variants.
     */
    public function testPagesCountProductsAndEachProductComesWithAllItsVariants(): void
    {
        foreach (['Buty A', 'Buty B', 'Buty C'] as $n => $name) {
            $this->saveProduct($name, TaxClassCode::Standard, axes: ['Rozmiar'], variants: array_map(
                static fn (int $size): array => ['id' => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012d', 100 * $n + $size), 'values' => [(string) $size]],
                [40, 41, 42],
            ));
        }

        $first = $this->get('perPage=2');
        $second = $this->get('perPage=2&after='.$this->next($first));

        self::assertSame(['Buty A', 'Buty B'], array_column($this->items($first), 'name'));
        self::assertSame(['Buty C'], array_column($this->items($second), 'name'));
        self::assertNull($second['next']);
        foreach ([...$this->items($first), ...$this->items($second)] as $item) {
            self::assertIsArray($item['variants']);
            self::assertSame(['40', '41', '42'], array_column($item['variants'], 'label'));
        }
    }

    public function testEmptyCatalogGivesAnEmptyList(): void
    {
        self::assertSame(['items' => [], 'next' => null], $this->get(''));
        self::assertResponseStatusCodeSame(200);
    }

    public function testDefaultPageHoldsTwentyFiveProductsAndTheCursorLeadsToTheRest(): void
    {
        for ($n = 1; $n <= 26; ++$n) {
            $this->saveProduct(\sprintf('Produkt %02d', $n), TaxClassCode::Standard);
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
            $this->saveProduct($name, TaxClassCode::Standard);
        }
        $this->saveProduct('Bidon obcy', TaxClassCode::Standard, $other);
        $this->saveProduct('Plecak', TaxClassCode::Standard);
        $category = $this->category->id()->toString();

        $first = $this->get('q=bidon&category='.$category.'&perPage=2');
        $second = $this->get('q=bidon&category='.$category.'&perPage=2&after='.$this->next($first));

        self::assertSame(['Bidon 1', 'Bidon 2'], array_column($this->items($first), 'name'));
        self::assertSame(['Bidon 3'], array_column($this->items($second), 'name'));
        self::assertNull($second['next']);
    }

    public function testPatternCharactersOfTheSearchAreLiteral(): void
    {
        $this->saveProduct('Nazwa 100%', TaxClassCode::Standard);
        $this->saveProduct('Nazwa 1000', TaxClassCode::Standard);

        self::assertSame(['Nazwa 100%'], array_column($this->items($this->get('q='.rawurlencode('0%'))), 'name'));
    }

    public function testResponseIsPrivateAndNotStored(): void
    {
        $this->get('');

        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringNotContainsString('public', $cacheControl);
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
    public function testEveryStaffRoleCanReadThePrices(array $roles): void
    {
        $this->saveProduct('Buty', TaxClassCode::Standard);

        $this->get('', TestTokenFactory::staff($roles));

        self::assertResponseStatusCodeSame(200);
    }

    public function testWithoutATokenTheRequestIsUnauthorized(): void
    {
        $this->getWithoutAToken('');

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

        self::assertResponseStatusCodeSame(400);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $problem = $this->json();
        self::assertSame(400, $problem['status']);
        self::assertStringNotContainsString('garbage', (string) $this->client->getResponse()->getContent());
    }

    public function testBoundariesOfTheParametersAreAccepted(): void
    {
        $this->saveProduct('Buty', TaxClassCode::Standard);

        foreach (['q=Bu', 'q='.str_repeat('a', 100), 'perPage=1', 'perPage=100'] as $query) {
            $this->get($query);

            self::assertResponseStatusCodeSame(200, $query);
        }
    }

    public function testAnUnknownCategoryIsNotFound(): void
    {
        $this->get('category=0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60');

        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    private function saveCategory(): Category
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);

        return $category;
    }

    /**
     * @param list<string>                                  $axes
     * @param list<array{id: string, values: list<string>}> $variants none for one default variant
     */
    private function saveProduct(string $name, TaxClassCode $taxClass, ?Category $category = null, array $axes = [], array $variants = []): Product
    {
        $attributes = ['mainCategory' => $category ?? $this->category, 'name' => $name, 'taxClass' => $taxClass];
        if ([] !== $variants) {
            $attributes += ['variantAxes' => $axes, 'variants' => $variants];
        }
        $product = ProductFactory::createOne($attributes);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        return $product;
    }

    private function defaultVariant(Product $product): string
    {
        return $product->variants()->toList()[0]->id()->toString();
    }

    /**
     * Requests the list as a staff member (an administrator unless the token says otherwise).
     *
     * @return array<mixed>
     */
    private function get(string $query, ?string $token = null): array
    {
        return $this->request($query, 'Bearer '.($token ?? TestTokenFactory::staff(['admin'])));
    }

    /**
     * @return array<mixed>
     */
    private function getWithoutAToken(string $query): array
    {
        return $this->request($query, null);
    }

    /**
     * @return array<mixed>
     */
    private function request(string $query, ?string $authorization): array
    {
        $server = null === $authorization ? [] : ['HTTP_AUTHORIZATION' => $authorization];
        $this->client->request('GET', '/api/v1/admin/prices'.('' === $query ? '' : '?'.$query), server: $server);

        return $this->json();
    }

    private function assertUnauthorized(): void
    {
        self::assertResponseStatusCodeSame(401);
        self::assertSame('Bearer', $this->client->getResponse()->headers->get('WWW-Authenticate'));
        self::assertStringStartsWith('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
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

    /**
     * @param array<mixed> $object
     */
    private function assertKeysMatchSchema(array $object, string $schemaName): void
    {
        $spec = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/openapi-admin.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($spec);
        self::assertIsArray($spec['components']);
        self::assertIsArray($spec['components']['schemas']);
        $schema = $spec['components']['schemas'][$schemaName] ?? null;
        self::assertIsArray($schema, $schemaName.' must be in openapi-admin.json.');
        self::assertIsArray($schema['properties']);
        self::assertIsArray($schema['required']);

        self::assertEqualsCanonicalizing(array_keys($schema['properties']), array_keys($object), $schemaName.' keys must match the schema.');
        self::assertEqualsCanonicalizing($schema['required'], array_keys($object), $schemaName.' requires every key.');
    }
}
