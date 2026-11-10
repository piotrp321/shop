<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\Application\Event\ProductChanged;
use App\Catalog\Domain\Product\ProductCreated;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Image\FlysystemProductImageStorage;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\InMemory\InMemoryProductImageStorage;
use App\Tests\Support\Security\TestTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST and PUT /api/v1/admin/products and POST …/publish and …/unpublish on PostgreSQL and the test bucket of Garage.
 */
final class WriteProductEndpointsTest extends WebTestCase
{
    use Factories;
    use ProductAdminEndpointTestTrait;

    private const string UNKNOWN_ID = '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60';
    private const string PRODUCTS = '/api/v1/admin/products';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function writes(): iterable
    {
        yield 'create' => ['POST', self::PRODUCTS];
        yield 'edit' => ['PUT', self::PRODUCTS.'/'.self::UNKNOWN_ID];
        yield 'publish' => ['POST', self::PRODUCTS.'/'.self::UNKNOWN_ID.'/publish'];
        yield 'unpublish' => ['POST', self::PRODUCTS.'/'.self::UNKNOWN_ID.'/unpublish'];
    }

    #[DataProvider('writes')]
    public function testWithoutATokenTheRequestIsUnauthorized(string $method, string $path): void
    {
        $this->api($method, $path, ['version' => 1], null);

        $this->assertUnauthorized();
    }

    #[DataProvider('writes')]
    public function testATokenOfTheShopRealmIsUnauthorized(string $method, string $path): void
    {
        $this->api($method, $path, ['version' => 1], TestTokenFactory::customer());

        $this->assertUnauthorized();
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function rolesWithoutTheRight(): iterable
    {
        foreach (self::writes() as $name => [$method, $path]) {
            foreach (['support', 'warehouse'] as $role) {
                yield $name.' as '.$role => [$method, $path, [$role]];
            }
            yield $name.' with no staff role' => [$method, $path, []];
        }
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('rolesWithoutTheRight')]
    public function testStaffWithoutAdminOrMarketingIsForbidden(string $method, string $path, array $roles): void
    {
        $this->api($method, $path, $this->body(['version' => 1]), TestTokenFactory::staff($roles));

        $this->assertProblem(403);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function managingRoles(): iterable
    {
        yield 'admin' => [['admin']];
        yield 'marketing' => [['marketing']];
        yield 'marketing among others' => [['support', 'marketing']];
    }

    /**
     * @param list<string> $roles
     */
    #[DataProvider('managingRoles')]
    public function testAdminAndMarketingCreateADraftWithTheirStaffIdRecorded(array $roles): void
    {
        $view = $this->api('POST', self::PRODUCTS, $this->body(), TestTokenFactory::staff($roles, ['sub' => '9d5ad4f4-2b61-4a35-8c2b-6d2b9a1d9e11']));

        self::assertResponseStatusCodeSame(201);
        $this->assertPrivateAndNotStored();
        self::assertSame('draft', $view['status']);
        self::assertSame(1, $view['version']);
        self::assertNull($view['firstPublishedAt']);
        self::assertFalse($view['slugLocked']);
        self::assertSame('buty-biegowe', $view['slug']);
        self::assertSame([['M'], ['L']], array_column(self::list($view['variants']), 'values'));
        $this->assertKeysMatchSchema($view, 'ProductEditViewResponse');
        self::assertSame('9d5ad4f4-2b61-4a35-8c2b-6d2b9a1d9e11', $this->connection->fetchOne('SELECT updated_by FROM catalog_product WHERE id = ?', [$view['id']]));
        self::assertSame($view, $this->api('GET', self::PRODUCTS.'/'.self::string($view['id'])));
    }

    public function testNewVariantsGetIdsAndAnEmptySlugIsDerivedFromTheName(): void
    {
        $view = $this->createProduct(['name' => 'Żółte Łapki 2', 'slug' => '']);

        self::assertSame('zolte-lapki-2', $view['slug']);
        $ids = array_column(self::list($view['variants']), 'variantId');
        self::assertCount(2, array_unique(array_map(self::string(...), $ids)));
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/D', self::string($id));
        }
    }

    public function testAProductWithoutAxesGetsItsDefaultVariant(): void
    {
        $view = $this->createProduct(['variantAxes' => [], 'variants' => [['variantId' => null, 'values' => []]], 'images' => []]);

        self::assertSame([], $view['variantAxes']);
        self::assertSame([], self::list($view['variants'])[0]['values']);
        self::assertSame([], $view['images']);
    }

    public function testBadDataIsRejectedWithTheExactViolations(): void
    {
        $this->api('POST', self::PRODUCTS, $this->body([
            'name' => ' ',
            'slug' => 'Zły Slug',
            'additionalCategoryIds' => [$this->category->id()->toString()],
            'taxClass' => 'luxury',
            'images' => [['key' => 'originals/x.jpg', 'alt' => '']],
            'variantAxes' => ['Kolor', 'kolor'],
            'variants' => [['variantId' => 'x', 'values' => ['a']], ['variantId' => null, 'values' => ['b', ' ']]],
        ]));

        self::assertSame([
            ['propertyPath' => 'name', 'title' => 'Podaj nazwę produktu.'],
            ['propertyPath' => 'slug', 'title' => 'Slug może zawierać tylko małe litery bez polskich znaków, cyfry i pojedyncze myślniki.'],
            ['propertyPath' => 'additionalCategoryIds[0]', 'title' => 'Kategoria główna nie może być też dodatkowa.'],
            ['propertyPath' => 'taxClass', 'title' => 'Wybierz klasę podatkową.'],
            ['propertyPath' => 'images[0].key', 'title' => 'Klucz zdjęcia jest nieprawidłowy.'],
            ['propertyPath' => 'images[0].alt', 'title' => 'Podaj tekst alternatywny zdjęcia.'],
            ['propertyPath' => 'variantAxes[1]', 'title' => 'Nazwa osi już jest użyta.'],
            ['propertyPath' => 'variants[0].variantId', 'title' => 'Identyfikator wariantu jest nieprawidłowy.'],
            ['propertyPath' => 'variants[1].values[1]', 'title' => 'Wartość musi mieć od 1 do 40 znaków.'],
        ], $this->violations());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    public function testADuplicateImageKeyAndADuplicateCombinationArePointedAt(): void
    {
        $key = $this->originalKey();
        $this->api('POST', self::PRODUCTS, $this->body([
            'images' => [['key' => $key, 'alt' => 'a'], ['key' => $key, 'alt' => 'b']],
            'variants' => [['variantId' => null, 'values' => ['M']], ['variantId' => null, 'values' => [' m ']]],
        ]));

        self::assertSame([
            ['propertyPath' => 'images[1].key', 'title' => 'To zdjęcie już jest w produkcie.'],
            ['propertyPath' => 'variants[1].values', 'title' => 'Taki wariant już istnieje.'],
        ], $this->violations());
    }

    /**
     * Pairs that lower() of PostgreSQL maps to the same text, but the comparison of the domain does not.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function valuesTheDomainTellsApart(): iterable
    {
        yield 'capital I with dot above and i' => ['İ', 'i'];
        yield 'capital sigma at the end of a word and small sigma' => ['ΑΣ', 'ασ'];
    }

    #[DataProvider('valuesTheDomainTellsApart')]
    public function testCombinationsTheDomainTellsApartAreCreated(string $first, string $second): void
    {
        $view = $this->createProduct(['variants' => [['variantId' => null, 'values' => [$first]], ['variantId' => null, 'values' => [$second]]]]);

        self::assertSame([[$first], [$second]], array_column(self::list($view['variants']), 'values'));
    }

    #[DataProvider('valuesTheDomainTellsApart')]
    public function testCombinationsTheDomainTellsApartAreSavedByAnEdit(string $first, string $second): void
    {
        $created = $this->createProduct(['variants' => [['variantId' => null, 'values' => [$second]]]]);
        $variants = self::list($created['variants']);

        $view = $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, [
            'variants' => [['variantId' => $variants[0]['variantId'], 'values' => [$second]], ['variantId' => null, 'values' => [$first]]],
        ]));

        self::assertResponseStatusCodeSame(200);
        self::assertSame([[$second], [$first]], array_column(self::list($view['variants']), 'values'));
    }

    public function testAnEditWithACombinationThatDiffersOnlyInAsciiCaseIsADuplicate(): void
    {
        $created = $this->createProduct();
        $variants = self::list($created['variants']);

        $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, [
            'variants' => [...$variants, ['variantId' => null, 'values' => ['l']]],
        ]));

        self::assertSame([['propertyPath' => 'variants[2].values', 'title' => 'Taki wariant już istnieje.']], $this->violations());
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$created['id']]));
    }

    public function testAnEmptyBodyListsEveryRequiredField(): void
    {
        $this->api('POST', self::PRODUCTS, []);

        self::assertSame(
            ['name', 'slug', 'shortDescription', 'mainCategoryId', 'additionalCategoryIds', 'taxClass', 'images', 'variantAxes', 'variants'],
            array_column($this->violations(), 'propertyPath'),
        );
    }

    public function testABodyThatIsNotJsonIsBadAndOneOfAnotherTypeIsUnsupported(): void
    {
        $this->client->request('POST', self::PRODUCTS, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])], content: '{"name":');
        $this->assertProblem(400);

        $this->client->request('POST', self::PRODUCTS, server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['admin'])], content: 'x');
        $this->assertProblem(415);
    }

    public function testATakenSlugIsAConflictOfItsOwnType(): void
    {
        $this->createProduct();

        $this->api('POST', self::PRODUCTS, $this->body(['name' => 'Inne buty']));

        $this->assertProblem(409, 'slug-taken');
    }

    public function testASlugDerivedFromTheNameThatIsTakenIsAConflictToo(): void
    {
        $this->createProduct(['slug' => 'buty-biegowe']);

        $this->api('POST', self::PRODUCTS, $this->body(['slug' => '']));

        $this->assertProblem(409, 'slug-taken');
    }

    public function testANameWithoutLettersAndAnEmptySlugIsAskedForASlug(): void
    {
        $this->api('POST', self::PRODUCTS, $this->body(['name' => '!!!', 'slug' => '']));

        self::assertSame([['propertyPath' => 'slug', 'title' => 'Podaj slug.']], $this->violations());
    }

    public function testAnUnknownCategoryIsAViolationAtItsFieldNotANotFound(): void
    {
        $this->api('POST', self::PRODUCTS, $this->body(['mainCategoryId' => self::UNKNOWN_ID]));
        self::assertSame([['propertyPath' => 'mainCategoryId', 'title' => 'Kategoria nie istnieje.']], $this->violations());

        $other = CategoryFactory::createOne();
        new \App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository($this->entityManager)->save($other);
        $this->api('POST', self::PRODUCTS, $this->body(['additionalCategoryIds' => [$other->id()->toString(), self::UNKNOWN_ID]]));
        self::assertSame([['propertyPath' => 'additionalCategoryIds[1]', 'title' => 'Kategoria nie istnieje.']], $this->violations());
    }

    public function testAnImageWithoutAnOriginalIsAViolationAtItsKey(): void
    {
        $this->api('POST', self::PRODUCTS, $this->body(['images' => [
            ['key' => $this->originalKey(), 'alt' => 'jest'],
            ['key' => 'originals/'.str_repeat('0', 64).'.jpg', 'alt' => 'nie ma'],
        ]]));

        self::assertSame([['propertyPath' => 'images[1].key', 'title' => 'Nie ma takiego zdjęcia. Wyślij plik ponownie.']], $this->violations());
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
    }

    public function testAnUnavailableImageStorageIsAServiceUnavailableOnCreateAndEdit(): void
    {
        $product = ProductFactory::createOne(['mainCategory' => $this->category, 'status' => ProductStatus::Draft, 'imageAlts' => ['front'], 'slug' => 'zapisany']);
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());
        $view = $this->api('GET', self::PRODUCTS.'/'.$product->id()->toString());
        $this->breakImageStorage();

        $this->api('POST', self::PRODUCTS, $this->body(['slug' => 'inny']));
        $created = $this->assertProblem(503);
        $this->api('PUT', self::PRODUCTS.'/'.$product->id()->toString(), $this->editBody($view));
        $edited = $this->assertProblem(503);

        self::assertArrayNotHasKey('detail', $created);
        self::assertArrayNotHasKey('detail', $edited);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product'));
        self::assertSame(1, $this->connection->fetchOne('SELECT version FROM catalog_product WHERE id = ?', [$product->id()->toString()]));
    }

    public function testEditReplacesTheDataAndRaisesTheVersion(): void
    {
        $created = $this->createProduct();
        $variants = self::list($created['variants']);

        $view = $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, [
            'name' => 'Buty trailowe',
            'slug' => 'buty-trailowe',
            'taxClass' => 'energy_bar',
            'images' => [['key' => $this->originalKey('sample.png'), 'alt' => 'Nowe zdjęcie']],
            'variants' => [['variantId' => $variants[1]['variantId'], 'values' => ['L']], ['variantId' => null, 'values' => ['XL']]],
        ]));

        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
        self::assertSame(2, $view['version']);
        self::assertSame('Buty trailowe', $view['name']);
        self::assertSame('buty-trailowe', $view['slug']);
        self::assertSame('energy_bar', $view['taxClass']);
        self::assertSame(['Nowe zdjęcie'], array_column(self::list($view['images']), 'alt'));
        $edited = self::list($view['variants']);
        self::assertSame($variants[1]['variantId'], $edited[0]['variantId']);
        self::assertNotSame($variants[0]['variantId'], $edited[1]['variantId']);
        self::assertSame($view, $this->api('GET', self::PRODUCTS.'/'.self::string($created['id'])));
        $this->assertKeysMatchSchema($view, 'ProductEditViewResponse');
    }

    public function testEditRecordsTheStaffMemberOfTheChange(): void
    {
        $created = $this->createProduct();

        $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created), TestTokenFactory::staff(['marketing'], ['sub' => '9d5ad4f4-2b61-4a35-8c2b-6d2b9a1d9e11']));

        self::assertSame('9d5ad4f4-2b61-4a35-8c2b-6d2b9a1d9e11', $this->connection->fetchOne('SELECT updated_by FROM catalog_product WHERE id = ?', [$created['id']]));
    }

    public function testEditAfterAStaleVersionIsAProductModifiedConflict(): void
    {
        $created = $this->createProduct();
        $path = self::PRODUCTS.'/'.self::string($created['id']);
        $this->api('PUT', $path, $this->editBody($created, ['name' => 'Pierwsza zmiana']));
        self::assertResponseStatusCodeSame(200);

        $this->api('PUT', $path, $this->editBody($created, ['name' => 'Druga zmiana']));

        $this->assertProblem(409, 'product-modified', 'Produkt został w międzyczasie zmieniony. Odśwież go i spróbuj ponownie.');
        self::assertSame('Pierwsza zmiana', $this->api('GET', $path)['name']);
    }

    public function testEditOfAnUnknownProductIsNotFound(): void
    {
        $this->api('PUT', self::PRODUCTS.'/'.self::UNKNOWN_ID, $this->body(['version' => 1]));

        $this->assertProblem(404);
    }

    public function testEditNeedsTheVersionAndTheSameFieldsAsCreate(): void
    {
        $created = $this->createProduct();
        $path = self::PRODUCTS.'/'.self::string($created['id']);

        $this->api('PUT', $path, $this->body());
        self::assertSame([['propertyPath' => 'version', 'title' => 'Podaj wersję produktu.']], $this->violations());

        $this->api('PUT', $path, $this->editBody($created, ['version' => '1', 'name' => '']));
        self::assertSame([
            ['propertyPath' => 'version', 'title' => 'Podaj wersję produktu.'],
            ['propertyPath' => 'name', 'title' => 'Podaj nazwę produktu.'],
        ], $this->violations());
    }

    public function testEditToASlugOfAnotherProductIsSlugTaken(): void
    {
        $this->createProduct(['slug' => 'zajety']);
        $created = $this->createProduct(['slug' => 'wolny']);

        $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created, ['slug' => 'zajety']));

        $this->assertProblem(409, 'slug-taken');
    }

    public function testEditOfAnUnknownVariantAndOfAMissingOriginalPointAtTheirFields(): void
    {
        $created = $this->createProduct();
        $path = self::PRODUCTS.'/'.self::string($created['id']);

        $this->api('PUT', $path, $this->editBody($created, ['variants' => [['variantId' => self::UNKNOWN_ID, 'values' => ['M']], ['variantId' => null, 'values' => ['L']]]]));
        self::assertSame([['propertyPath' => 'variants[0].variantId', 'title' => 'Wariant nie należy do tego produktu.']], $this->violations());

        $this->api('PUT', $path, $this->editBody($created, ['images' => [['key' => 'originals/'.str_repeat('1', 64).'.jpg', 'alt' => 'brak']]]));
        self::assertSame([['propertyPath' => 'images[0].key', 'title' => 'Nie ma takiego zdjęcia. Wyślij plik ponownie.']], $this->violations());
    }

    public function testThePublishedSlugIsLocked(): void
    {
        $published = $this->publish($this->createProduct());

        $this->api('PUT', self::PRODUCTS.'/'.self::string($published['id']), $this->editBody($published, ['slug' => 'inny-slug']));

        $this->assertProblem(409, 'slug-locked');
    }

    public function testAPublishedProductKeepsItsSlugWhenTheEditSendsNone(): void
    {
        $published = $this->publish($this->createProduct());

        $view = $this->api('PUT', self::PRODUCTS.'/'.self::string($published['id']), $this->editBody($published, ['slug' => '', 'name' => 'Nowa nazwa']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('buty-biegowe', $view['slug']);
    }

    public function testTheLastImageOfAPublishedProductCannotBeRemoved(): void
    {
        $published = $this->publish($this->createProduct());

        $this->api('PUT', self::PRODUCTS.'/'.self::string($published['id']), $this->editBody($published, ['images' => []]));

        $this->assertProblem(409, 'product-not-publishable');
    }

    public function testAPublishedProductKeepsItsVariantsInIdentity(): void
    {
        $published = $this->publish($this->createProduct(['variantAxes' => ['Kolor'], 'variants' => [['variantId' => null, 'values' => ['czarne']]]]));
        $path = self::PRODUCTS.'/'.self::string($published['id']);
        $id = self::list($published['variants'])[0]['variantId'];

        $this->api('PUT', $path, $this->editBody($published, ['variants' => [['variantId' => $id, 'values' => ['Czarne']]]]));
        self::assertResponseStatusCodeSame(200);
        $renamed = $this->api('GET', $path);

        $this->api('PUT', $path, $this->editBody($renamed, ['variants' => [['variantId' => $id, 'values' => ['białe']]]]));
        $this->assertProblem(409, 'variant-locked');

        $this->api('PUT', $path, $this->editBody($renamed, ['variantAxes' => ['Odcień']]));
        $this->assertProblem(409, 'variant-locked');
    }

    public function testAVariantWithStockCannotBeRemoved(): void
    {
        $published = $this->publish($this->createProduct());
        $path = self::PRODUCTS.'/'.self::string($published['id']);
        $variants = self::list($published['variants']);
        $this->connection->executeStatement(
            'INSERT INTO inventory_stock_item (variant_id, physical, reserved, version, updated_at) VALUES (?, 5, 0, 1, now())',
            [$variants[1]['variantId']],
        );

        $this->api('PUT', $path, $this->editBody($published, ['variants' => [$variants[0]]]));
        $problem = $this->assertProblem(409, 'variant-in-use');
        self::assertStringContainsString('L', self::string($problem['detail']));

        $this->connection->executeStatement('UPDATE inventory_stock_item SET physical = 0 WHERE variant_id = ?', [$variants[1]['variantId']]);
        $this->api('PUT', $path, $this->editBody($published, ['variants' => [$variants[0]]]));
        self::assertResponseStatusCodeSame(200);
    }

    public function testPublishPublishesADraftWithImages(): void
    {
        $created = $this->createProduct();

        $view = $this->api('POST', self::PRODUCTS.'/'.self::string($created['id']).'/publish', ['version' => 1]);

        self::assertResponseStatusCodeSame(200);
        $this->assertPrivateAndNotStored();
        self::assertSame('published', $view['status']);
        self::assertSame(2, $view['version']);
        self::assertTrue($view['slugLocked']);
        self::assertIsString($view['firstPublishedAt']);
        $this->assertKeysMatchSchema($view, 'ProductEditViewResponse');
    }

    public function testPublishWithoutImagesIsNotPublishable(): void
    {
        $created = $this->createProduct(['images' => []]);

        $this->api('POST', self::PRODUCTS.'/'.self::string($created['id']).'/publish', ['version' => 1]);

        $this->assertProblem(409, 'product-not-publishable');
        self::assertSame('draft', $this->api('GET', self::PRODUCTS.'/'.self::string($created['id']))['status']);
    }

    public function testPublishOfAPublishedProductChangesNothing(): void
    {
        $published = $this->publish($this->createProduct());

        $view = $this->api('POST', self::PRODUCTS.'/'.self::string($published['id']).'/publish', ['version' => $published['version']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($published, $view);
    }

    public function testUnpublishReturnsToDraftAndKeepsTheSlugLocked(): void
    {
        $published = $this->publish($this->createProduct());

        $view = $this->api('POST', self::PRODUCTS.'/'.self::string($published['id']).'/unpublish', ['version' => $published['version']]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('draft', $view['status']);
        self::assertSame(3, $view['version']);
        self::assertTrue($view['slugLocked']);
        $this->assertKeysMatchSchema($view, 'ProductEditViewResponse');
    }

    public function testUnpublishOfADraftChangesNothing(): void
    {
        $created = $this->createProduct();

        $view = $this->api('POST', self::PRODUCTS.'/'.self::string($created['id']).'/unpublish', ['version' => 1]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($created, $view);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicationActions(): iterable
    {
        yield 'publish' => ['publish'];
        yield 'unpublish' => ['unpublish'];
    }

    #[DataProvider('publicationActions')]
    public function testAStaleVersionIsProductModifiedEvenWhenTheActionWouldChangeNothing(string $action): void
    {
        $created = $this->createProduct();
        $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created));

        $this->api('POST', self::PRODUCTS.'/'.self::string($created['id']).'/'.$action, ['version' => 1]);

        $this->assertProblem(409, 'product-modified');
    }

    #[DataProvider('publicationActions')]
    public function testThePublicationActionsOfAnUnknownProductAreNotFound(string $action): void
    {
        $this->api('POST', self::PRODUCTS.'/'.self::UNKNOWN_ID.'/'.$action, ['version' => 1]);

        $this->assertProblem(404);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function badVersions(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['version' => null]];
        yield 'a string' => [['version' => '1']];
        yield 'zero' => [['version' => 0]];
        yield 'a fraction' => [['version' => 1.5]];
    }

    /**
     * @param array<mixed> $body
     */
    #[DataProvider('badVersions')]
    public function testThePublicationActionsNeedAnIntegerVersion(array $body): void
    {
        $created = $this->createProduct();

        $this->api('POST', self::PRODUCTS.'/'.self::string($created['id']).'/publish', $body);
        self::assertSame([['propertyPath' => 'version', 'title' => 'Podaj wersję produktu.']], $this->violations());

        $this->api('POST', self::PRODUCTS.'/'.self::string($created['id']).'/unpublish', $body);
        self::assertSame([['propertyPath' => 'version', 'title' => 'Podaj wersję produktu.']], $this->violations());
    }

    public function testEveryChangingWriteRaisesTheIntegrationProductChangedForItsConsumers(): void
    {
        $created = $this->createProduct();
        self::assertSame(0, $this->sentInTheLastRequest(ProductChanged::class), 'creation raises ProductCreated, not ProductChanged');
        self::assertSame(1, $this->sentInTheLastRequest(ProductCreated::class));

        $edited = $this->api('PUT', self::PRODUCTS.'/'.self::string($created['id']), $this->editBody($created));
        self::assertSame(1, $this->sentInTheLastRequest(ProductChanged::class));

        $published = $this->publish($edited);
        self::assertSame(1, $this->sentInTheLastRequest(ProductChanged::class));

        $this->api('POST', self::PRODUCTS.'/'.self::string($published['id']).'/publish', ['version' => $published['version']]);
        self::assertSame(0, $this->sentInTheLastRequest(ProductChanged::class), 'publishing a published product changes nothing');

        $this->api('POST', self::PRODUCTS.'/'.self::string($published['id']).'/unpublish', ['version' => $published['version']]);
        self::assertSame(1, $this->sentInTheLastRequest(ProductChanged::class));
    }

    /**
     * The transports are reset when a request ends, so the outbox holds what the last request sent.
     *
     * @param class-string $event
     */
    private function sentInTheLastRequest(string $event): int
    {
        $outbox = self::getContainer()->get('messenger.transport.outbox');
        self::assertInstanceOf(InMemoryTransport::class, $outbox);

        return \count(array_filter($outbox->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof $event));
    }

    private function breakImageStorage(): void
    {
        $storage = new InMemoryProductImageStorage();
        $storage->unavailable = true;
        self::getContainer()->set(FlysystemProductImageStorage::class, $storage);
    }
}
