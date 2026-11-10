<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Domain\Product\TaxClassCode;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /api/v1/admin/products, GET /api/v1/admin/products/{id} and GET /api/v1/admin/tax-classes on PostgreSQL.
 */
final class BackofficeProductsEndpointTest extends WebTestCase
{
    use Factories;
    use ProductAdminEndpointTestTrait;

    private const string UNKNOWN_ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';

    /**
     * @return iterable<string, array{string}>
     */
    public static function readPaths(): iterable
    {
        yield 'list' => ['/api/v1/admin/products'];
        yield 'view' => ['/api/v1/admin/products/'.self::UNKNOWN_ID];
        yield 'tax classes' => ['/api/v1/admin/tax-classes'];
    }

    #[DataProvider('readPaths')]
    public function testWithoutATokenTheRequestIsUnauthorized(string $path): void
    {
        $this->api('GET', $path, token: null);

        $this->assertUnauthorized();
    }

    #[DataProvider('readPaths')]
    public function testATokenOfTheShopRealmIsUnauthorized(string $path): void
    {
        $this->api('GET', $path, token: TestTokenFactory::customer());

        $this->assertUnauthorized();
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
        yield 'no staff role' => [[]];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('staffRoles')]
    public function testEveryStaffMemberReadsTheListTheViewAndTheTaxClasses(array $roles): void
    {
        $view = $this->createProduct();
        $token = TestTokenFactory::staff($roles);

        $this->api('GET', '/api/v1/admin/products', token: $token);
        self::assertResponseStatusCodeSame(200);
        $this->api('GET', '/api/v1/admin/products/'.self::string($view['id']), token: $token);
        self::assertResponseStatusCodeSame(200);
        $this->api('GET', '/api/v1/admin/tax-classes', token: $token);
        self::assertResponseStatusCodeSame(200);
    }

    public function testTheListHoldsTheSummaryOfEachProduct(): void
    {
        $view = $this->createProduct(['variants' => [['variantId' => null, 'values' => ['M']], ['variantId' => null, 'values' => ['L']], ['variantId' => null, 'values' => ['XL']]]]);

        $body = $this->api('GET', '/api/v1/admin/products');

        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
        self::assertNull($body['next']);
        $items = self::list($body['items']);
        self::assertCount(1, $items);
        $item = $items[0];
        self::assertSame($view['id'], $item['id']);
        self::assertSame('Buty biegowe', $item['name']);
        self::assertSame('buty-biegowe', $item['slug']);
        self::assertSame('draft', $item['status']);
        self::assertSame(['id' => $this->category->id()->toString(), 'name' => 'Obuwie'], $item['mainCategory']);
        self::assertSame(3, $item['variantCount']);
        self::assertSame($view['updatedAt'], $item['updatedAt']);
        self::assertIsArray($item['image']);
        self::assertSame('Buty z boku', $item['image']['alt']);
        self::assertSame('thumbnail', $item['image']['size']);
        self::assertSame('1:1', $item['image']['aspectRatio']);
        self::assertIsArray($item['image']['variants']);
        self::assertIsArray($item['image']['variants']['webp']);
        self::assertSame([96, 192], array_column($item['image']['variants']['webp'], 'width'));
        self::assertMatchesRegularExpression('#^https?://\S+$#', self::string(self::list($item['image']['variants']['webp'])[0]['url']));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', self::string($item['updatedAt']));
        $this->assertKeysMatchSchema($body, 'ProductListResponse');
        $this->assertKeysMatchSchema($item, 'ProductListItemResponse');
        $this->assertKeysMatchSchema($item['mainCategory'], 'ProductCategoryResponse');
        $this->assertKeysMatchSchema($item['image'], 'ImageViewResponse');
    }

    public function testAProductWithoutImagesHasNoImage(): void
    {
        $this->createProduct(['images' => []]);

        $items = self::list($this->api('GET', '/api/v1/admin/products')['items']);

        self::assertNull($items[0]['image']);
    }

    public function testAnEmptyCatalogGivesAnEmptyList(): void
    {
        self::assertSame(['items' => [], 'next' => null], $this->api('GET', '/api/v1/admin/products'));
    }

    public function testTheListFiltersByStatusWithKeysetPaging(): void
    {
        foreach (['Szkic A', 'Szkic B', 'Szkic C'] as $name) {
            $this->createProduct(['name' => $name, 'slug' => '']);
        }
        foreach (['Opublikowany A', 'Opublikowany B'] as $name) {
            $this->publish($this->createProduct(['name' => $name, 'slug' => '']));
        }

        $first = $this->api('GET', '/api/v1/admin/products?status=draft&perPage=2');
        $second = $this->api('GET', '/api/v1/admin/products?status=draft&perPage=2&after='.self::string($first['next']));
        $published = $this->api('GET', '/api/v1/admin/products?status=published');

        self::assertSame(['Szkic A', 'Szkic B'], array_column(self::list($first['items']), 'name'));
        self::assertSame(['Szkic C'], array_column(self::list($second['items']), 'name'));
        self::assertNull($second['next']);
        self::assertSame(['Opublikowany A', 'Opublikowany B'], array_column(self::list($published['items']), 'name'));
        self::assertSame(['published', 'published'], array_column(self::list($published['items']), 'status'));
        self::assertCount(5, self::list($this->api('GET', '/api/v1/admin/products')['items']));
    }

    public function testTheListSearchesByNameAndFiltersByCategory(): void
    {
        $other = CategoryFactory::createOne();
        new \App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository($this->entityManager)->save($other);
        $this->createProduct(['name' => 'Bidon 1', 'slug' => 'bidon-1']);
        $this->createProduct(['name' => 'Bidon obcy', 'slug' => 'bidon-obcy', 'mainCategoryId' => $other->id()->toString()]);
        $this->createProduct(['name' => 'Plecak', 'slug' => 'plecak']);

        $byName = $this->api('GET', '/api/v1/admin/products?q=bidon');
        $byBoth = $this->api('GET', '/api/v1/admin/products?q=bidon&category='.$other->id()->toString().'&status=draft');

        self::assertSame(['Bidon 1', 'Bidon obcy'], array_column(self::list($byName['items']), 'name'));
        self::assertSame(['Bidon obcy'], array_column(self::list($byBoth['items']), 'name'));
    }

    public function testTheListFiltersByCategoryGivenInUpperCase(): void
    {
        $other = CategoryFactory::createOne();
        new \App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository($this->entityManager)->save($other);
        $this->createProduct(['name' => 'Bidon 1', 'slug' => 'bidon-1']);
        $this->createProduct(['name' => 'Bidon obcy', 'slug' => 'bidon-obcy', 'mainCategoryId' => $other->id()->toString()]);

        $upper = $this->api('GET', '/api/v1/admin/products?category='.strtoupper($other->id()->toString()));

        self::assertSame(['Bidon obcy'], array_column(self::list($upper['items']), 'name'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedQueries(): iterable
    {
        yield 'one character' => ['q=a'];
        yield 'term over 100 characters' => ['q='.str_repeat('a', 101)];
        yield 'unknown status' => ['status=archived'];
        yield 'empty status' => ['status='];
        yield 'zero per page' => ['perPage=0'];
        yield 'per page over 100' => ['perPage=101'];
        yield 'category not a uuid' => ['category=shoes'];
        yield 'malformed cursor' => ['after=garbage!'];
        yield 'cursor of another shape' => ['after=aGVsbG8'];
    }

    #[DataProvider('rejectedQueries')]
    public function testBadParametersAreRejectedWithViolations(string $query): void
    {
        $this->api('GET', '/api/v1/admin/products?'.$query);

        self::assertNotSame([], $this->violations(400));
        self::assertStringNotContainsString('garbage', (string) $this->client->getResponse()->getContent());
    }

    public function testAnUnknownCategoryIsNotFound(): void
    {
        $this->api('GET', '/api/v1/admin/products?category='.self::UNKNOWN_ID);

        $this->assertProblem(404);
    }

    public function testTheViewHoldsEverythingTheFormNeeds(): void
    {
        $extra = CategoryFactory::createOne();
        new \App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository($this->entityManager)->save($extra);
        $created = $this->createProduct(['additionalCategoryIds' => [$extra->id()->toString()]]);

        $view = $this->api('GET', '/api/v1/admin/products/'.self::string($created['id']));

        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
        self::assertSame($created, $view);
        self::assertSame(1, $view['version']);
        self::assertSame('draft', $view['status']);
        self::assertNull($view['firstPublishedAt']);
        self::assertFalse($view['slugLocked']);
        self::assertSame(['Buty biegowe', 'buty-biegowe', 'Lekkie buty na szlak.'], [$view['name'], $view['slug'], $view['shortDescription']]);
        self::assertSame($this->category->id()->toString(), $view['mainCategoryId']);
        self::assertSame([$extra->id()->toString()], $view['additionalCategoryIds']);
        self::assertSame('standard', $view['taxClass']);
        self::assertSame(['Rozmiar'], $view['variantAxes']);
        $images = self::list($view['images']);
        self::assertCount(1, $images);
        self::assertSame('Buty z boku', $images[0]['alt']);
        self::assertMatchesRegularExpression('#^originals/[a-f0-9]{64}\.jpg$#D', self::string($images[0]['key']));
        self::assertIsArray($images[0]['preview']);
        self::assertSame('thumbnail', $images[0]['preview']['size']);
        $variants = self::list($view['variants']);
        self::assertSame([['M'], ['L']], array_column($variants, 'values'));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/D', self::string($variants[0]['variantId']));
        $this->assertKeysMatchSchema($view, 'ProductEditViewResponse');
        $this->assertKeysMatchSchema($images[0], 'ProductEditImageResponse');
        $this->assertKeysMatchSchema($variants[0], 'ProductEditVariantResponse');
    }

    public function testTheViewOfAPublishedProductIsSlugLocked(): void
    {
        $published = $this->publish($this->createProduct());

        $view = $this->api('GET', '/api/v1/admin/products/'.self::string($published['id']));

        self::assertSame('published', $view['status']);
        self::assertTrue($view['slugLocked']);
        self::assertIsString($view['firstPublishedAt']);
        self::assertSame(2, $view['version']);
    }

    public function testTheViewOfAnUnknownProductIsNotFound(): void
    {
        $this->api('GET', '/api/v1/admin/products/'.self::UNKNOWN_ID);

        $this->assertProblem(404);
    }

    public function testTheViewOfSomethingThatIsNoIdIsNotFound(): void
    {
        $this->api('GET', '/api/v1/admin/products/not-an-id');

        $this->assertProblem(404);
    }

    public function testTheTaxClassesAreListedWithPolishNames(): void
    {
        $classes = $this->api('GET', '/api/v1/admin/tax-classes');

        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
        self::assertTrue(array_is_list($classes));
        self::assertSame(array_map(static fn (TaxClassCode $code): string => $code->value, TaxClassCode::cases()), array_column($classes, 'code'));
        self::assertSame(['code' => 'printed_book', 'name' => 'Książki drukowane'], $classes[1]);
        $this->assertKeysMatchSchema(self::list($classes)[0], 'TaxClassResponse');
    }
}
