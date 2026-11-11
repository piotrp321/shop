<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductAlreadyExists;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\SlugAlreadyTaken;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Domain\Product\VariantAlreadyExists;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Service\ResetInterface;
use Zenstruck\Foundry\Test\Factories;

final class DoctrineProductRepositoryTest extends KernelTestCase
{
    use CommitsRowsOnSecondConnection;
    use Factories;

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineCategoryRepository $categories;
    private DoctrineProductRepository $products;

    protected function setUp(): void
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->categories = new DoctrineCategoryRepository($entityManager);
        $this->products = new DoctrineProductRepository($entityManager);
    }

    public function testSavedProductIsReadBackWithAllState(): void
    {
        [$main, $a, $b] = $this->savedCategories(3);
        $product = ProductFactory::createOne([
            'mainCategory' => $main,
            'additionalCategories' => [$b, $a],
            'taxClass' => TaxClassCode::FreezeDriedMeal,
            'imageAlts' => ['front', 'side', 'back'],
        ]);
        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        $loaded = $this->products->get($product->id());

        self::assertSame([], $loaded->releaseEvents());
        self::assertSame($product->name()->value(), $loaded->name()->value());
        self::assertSame($product->slug()->value(), $loaded->slug()->value());
        self::assertSame(TaxClassCode::FreezeDriedMeal, $loaded->taxClass());
        self::assertSame($main->id()->toString(), $loaded->categories()->main()->toString());
        $expectedAdditional = [$a->id()->toString(), $b->id()->toString()];
        sort($expectedAdditional);
        self::assertSame($expectedAdditional, array_map(static fn ($id): string => $id->toString(), $loaded->categories()->additional()));
        self::assertSame(
            array_map(static fn ($image): array => [$image->key()->toString(), $image->alt(), $image->position()], $product->images()->toList()),
            array_map(static fn ($image): array => [$image->key()->toString(), $image->alt(), $image->position()], $loaded->images()->toList()),
        );
    }

    public function testAllReturnsAdditionalCategoriesSortedById(): void
    {
        [$main] = $this->savedCategories(1);
        $last = CategoryFactory::createOne(['id' => 'ffffffff-ffff-4fff-bfff-ffffffffffff']);
        $first = CategoryFactory::createOne(['id' => '11111111-1111-4111-8111-111111111111']);
        $this->categories->save($last);
        $this->categories->save($first);
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$last, $first]]), null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        $products = [...$this->products->all()];

        self::assertCount(1, $products);
        self::assertSame(
            [$first->id()->toString(), $last->id()->toString()],
            array_map(static fn ($id): string => $id->toString(), $products[0]->categories()->additional()),
        );
    }

    public function testGetInSameUnitOfWorkReturnsAdditionalCategoriesSortedById(): void
    {
        [$main] = $this->savedCategories(1);
        $last = CategoryFactory::createOne(['id' => 'ffffffff-ffff-4fff-bfff-ffffffffffff']);
        $first = CategoryFactory::createOne(['id' => '11111111-1111-4111-8111-111111111111']);
        $this->categories->save($last);
        $this->categories->save($first);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$last, $first]]);
        $this->products->save($product, null, null, new \DateTimeImmutable());

        self::assertSame(
            [$first->id()->toString(), $last->id()->toString()],
            array_map(static fn ($id): string => $id->toString(), $this->products->get($product->id())->categories()->additional()),
        );
    }

    public function testOnlyAdditionalCategoriesHaveAssignmentRows(): void
    {
        [$main, $additional, $otherMain] = $this->savedCategories(3);
        $withAdditional = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$additional]]);
        $withoutAdditional = ProductFactory::createOne(['mainCategory' => $otherMain]);
        $this->products->save($withAdditional, null, null, new \DateTimeImmutable());
        $this->products->save($withoutAdditional, null, null, new \DateTimeImmutable());

        self::assertSame([$additional->id()->toString()], $this->assignedCategoryIds($withAdditional));
        self::assertSame([], $this->assignedCategoryIds($withoutAdditional));
    }

    public function testSavingExistingProductReplacesItsAdditionalCategories(): void
    {
        [$main, $kept, $removed, $added] = $this->savedCategories(4);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$kept, $removed]]);
        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        $this->products->save(ProductFactory::createOne([
            'id' => $product->id()->toString(),
            'slug' => $product->slug()->value(),
            'mainCategory' => $main,
            'additionalCategories' => [$kept, $added],
        ]), null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        $expected = [$kept->id()->toString(), $added->id()->toString()];
        sort($expected);
        self::assertSame($expected, $this->assignedCategoryIds($product));
        self::assertSame($expected, array_map(
            static fn ($id): string => $id->toString(),
            $this->products->get($product->id())->categories()->additional(),
        ));
        self::assertSame(1, $this->products->count());
    }

    public function testGetOfUnknownIdThrowsProductNotFound(): void
    {
        $this->expectException(ProductNotFound::class);

        $this->products->get(ProductId::fromString('99999999-9999-4999-8999-999999999999'));
    }

    public function testCountsAllProductsAndProductsWithoutImages(): void
    {
        [$main] = $this->savedCategories(1);
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'imageAlts' => ['front']]), null, null, new \DateTimeImmutable());
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main]), null, null, new \DateTimeImmutable());
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main]), null, null, new \DateTimeImmutable());

        self::assertSame(3, $this->products->count());
        self::assertSame(2, $this->products->countWithoutImages());
        self::assertCount(3, [...$this->products->all()]);
    }

    public function testDuplicateSlugThrowsSlugAlreadyTakenAndLeavesNoRows(): void
    {
        [$main, $additional] = $this->savedCategories(2);
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'slug' => 'same-slug']), null, null, new \DateTimeImmutable());
        $duplicate = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$additional], 'slug' => 'same-slug']);

        try {
            $this->products->save($duplicate, null, null, new \DateTimeImmutable());
            self::fail('Expected SlugAlreadyTaken.');
        } catch (SlugAlreadyTaken) {
        }

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product WHERE id = ?', [$duplicate->id()->toString()]));
        self::assertSame([], $this->assignedCategoryIds($duplicate));
    }

    public function testPrimaryKeyViolationOnInsertThrowsProductAlreadyExistsAndLeavesTheCompetingRow(): void
    {
        $category = CategoryFactory::createOne();
        $this->commitRow($this->entityManager, 'catalog_category', [
            'id' => $category->id()->toString(),
            'name' => 'Committed',
            'slug' => $category->slug()->value(),
            'path' => $category->path()->toString(),
        ]);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'slug' => 'new-slug']);
        // The competing request commits the same id after this repository read the id and found nothing.
        $this->commitRowBeforeNextInsert($this->entityManager, 'catalog_product', [
            'id' => $product->id()->toString(),
            'slug' => 'competing-slug',
            'name' => 'Competing',
            'short_description' => '',
            'primary_category_id' => $category->id()->toString(),
            'tax_class' => 'standard',
            'images' => '[]',
            'variant_axes' => '[]',
            'version' => '1',
            'status' => 'published',
            'updated_at' => '2026-11-07 08:00:00+00',
        ]);

        try {
            $this->products->save($product, null, null, new \DateTimeImmutable());
            self::fail('Expected ProductAlreadyExists.');
        } catch (ProductAlreadyExists) {
        }

        self::assertSame('Competing', $this->committedValue($this->entityManager, 'SELECT name FROM catalog_product WHERE id = ?', $product->id()->toString()));
    }

    public function testRepositoryWorksAgainAfterSlugAlreadyTakenOnceServicesAreReset(): void
    {
        [$main] = $this->savedCategories(1);
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'slug' => 'same-slug']), null, null, new \DateTimeImmutable());
        try {
            $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'slug' => 'same-slug']), null, null, new \DateTimeImmutable());
            self::fail('Expected SlugAlreadyTaken.');
        } catch (SlugAlreadyTaken) {
        }
        self::assertFalse($this->entityManager->isOpen());

        // The kernel resets services between requests and Messenger between messages (kernel.reset).
        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        $next = ProductFactory::createOne(['mainCategory' => $main]);
        $this->products->save($next, null, null, new \DateTimeImmutable());
        self::assertSame($next->slug()->value(), $this->products->get($next->id())->slug()->value());
    }

    public function testMissingMainCategoryViolatesForeignKey(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->products->save(ProductFactory::createOne(['mainCategory' => CategoryFactory::createOne()]), null, null, new \DateTimeImmutable());
    }

    public function testMissingAdditionalCategoryViolatesForeignKey(): void
    {
        [$main] = $this->savedCategories(1);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [CategoryFactory::createOne()]]), null, null, new \DateTimeImmutable());
    }

    public function testDeletingProductRemovesItsAssignments(): void
    {
        [$main, $additional] = $this->savedCategories(2);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$additional]]);
        $this->products->save($product, null, null, new \DateTimeImmutable());

        $this->connection->executeStatement('DELETE FROM catalog_product WHERE id = ?', [$product->id()->toString()]);

        self::assertSame([], $this->assignedCategoryIds($product));
    }

    public function testAssignedAdditionalCategoryCannotBeDeleted(): void
    {
        [$main, $additional] = $this->savedCategories(2);
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$additional]]), null, null, new \DateTimeImmutable());

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->connection->executeStatement('DELETE FROM catalog_category WHERE id = ?', [$additional->id()->toString()]);
    }

    public function testSchemaHasNotNullPrimaryCategoryAndIndexes(): void
    {
        self::assertSame('NO', $this->connection->fetchOne(
            "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'catalog_product' AND column_name = 'primary_category_id'",
        ));
        /** @var list<string> $definitions */
        $definitions = $this->connection->fetchFirstColumn(
            "SELECT indexdef FROM pg_indexes WHERE tablename IN ('catalog_product', 'catalog_product_category')",
        );
        $indexes = implode("\n", $definitions);
        self::assertMatchesRegularExpression('/ON public\.catalog_product USING btree \(primary_category_id\)/', $indexes);
        self::assertMatchesRegularExpression('/ON public\.catalog_product_category USING btree \(category_id\)/', $indexes);
    }

    public function testAxesAndVariantsAreReadBackInTheirOrder(): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne([
            'mainCategory' => $main,
            'variantAxes' => ['Kolor', 'Rozmiar'],
            'variants' => [
                ['id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'values' => ['czarny', '42']],
                ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => ['czarny', '43']],
                ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'values' => ['Żółty', '42']],
            ],
        ]);
        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        $loaded = $this->products->get($product->id());

        self::assertSame(['Kolor', 'Rozmiar'], $loaded->variantAxes()->names());
        self::assertSame(
            [
                ['cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'czarny, 42'],
                ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'czarny, 43'],
                ['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'Żółty, 42'],
            ],
            array_map(static fn (ProductVariant $v): array => [$v->id()->toString(), $v->label()], $loaded->variants()->toList()),
        );
        self::assertSame(
            [[0, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'], [1, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'], [2, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']],
            array_map(
                static fn (array $row): array => [$row['position'], $row['id']],
                $this->connection->fetchAllAssociative('SELECT id, position FROM catalog_product_variant WHERE product_id = ? ORDER BY position', [$product->id()->toString()]),
            ),
        );
    }

    public function testAllReturnsTheVariantsOfEveryProduct(): void
    {
        [$main] = $this->savedCategories(1);
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'variantAxes' => ['Rozmiar'], 'variants' => [
            ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => ['S']],
            ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'values' => ['M']],
        ]]), null, null, new \DateTimeImmutable());
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main]), null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        $counts = array_map(static fn (Product $p): int => $p->variants()->count(), [...$this->products->all()]);
        sort($counts);

        self::assertSame([1, 2], $counts);
    }

    public function testDefaultVariantIsStoredWithoutValues(): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne(['mainCategory' => $main]);
        $this->products->save($product, null, null, new \DateTimeImmutable());

        self::assertSame('[]', $this->connection->fetchOne('SELECT variant_axes::text FROM catalog_product WHERE id = ?', [$product->id()->toString()]));
        $row = $this->connection->fetchAssociative('SELECT option_values::text AS option_values, values_key, position FROM catalog_product_variant WHERE product_id = ?', [$product->id()->toString()]);
        self::assertSame(['option_values' => '[]', 'values_key' => '[]', 'position' => 0], $row);
        self::assertNotSame($product->id()->toString(), $product->variants()->toList()[0]->id()->toString());
    }

    public function testDeletingProductRemovesItsVariants(): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'variantAxes' => ['Rozmiar'], 'variants' => [
            ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => ['S']],
            ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'values' => ['M']],
        ]]);
        $this->products->save($product, null, null, new \DateTimeImmutable());
        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product_variant'));

        $this->connection->executeStatement('DELETE FROM catalog_product WHERE id = ?', [$product->id()->toString()]);

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product_variant'));
    }

    public function testSavingTheSameProductAgainKeepsItsVariantsAndSavingDifferentOnesReplacesThem(): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'variantAxes' => ['Rozmiar'], 'variants' => [
            ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => ['S']],
            ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'values' => ['M']],
        ]]);
        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();
        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        self::assertSame(['S', 'M'], array_map(static fn (ProductVariant $v): string => $v->label(), $this->products->get($product->id())->variants()->toList()));

        $this->products->save(ProductFactory::createOne([
            'id' => $product->id()->toString(),
            'slug' => $product->slug()->value(),
            'mainCategory' => $main,
            'variantAxes' => ['Kolor'],
            'variants' => [
                ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'values' => ['zielony']],
                ['id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'values' => ['czarny']],
            ],
        ]), null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        $loaded = $this->products->get($product->id());
        self::assertSame(['Kolor'], $loaded->variantAxes()->names());
        self::assertSame(
            [['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'zielony'], ['dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'czarny']],
            array_map(static fn (ProductVariant $v): array => [$v->id()->toString(), $v->label()], $loaded->variants()->toList()),
        );
        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product_variant'));
    }

    public function testVariantIdOfAnotherProductThrowsVariantAlreadyExistsAndLeavesNoRows(): void
    {
        [$main] = $this->savedCategories(1);
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'variants' => [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => []]]]), null, null, new \DateTimeImmutable());
        $other = ProductFactory::createOne(['mainCategory' => $main, 'variants' => [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => []]]]);

        try {
            $this->products->save($other, null, null, new \DateTimeImmutable());
            self::fail('Expected VariantAlreadyExists.');
        } catch (VariantAlreadyExists) {
        }

        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product WHERE id = ?', [$other->id()->toString()]));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM catalog_product_variant'));
    }

    public function testVariantIdTakenByACompetingRequestThrowsVariantAlreadyExists(): void
    {
        $category = CategoryFactory::createOne();
        $this->commitRow($this->entityManager, 'catalog_category', [
            'id' => $category->id()->toString(),
            'name' => 'Committed',
            'slug' => $category->slug()->value(),
            'path' => $category->path()->toString(),
        ]);
        $competing = ProductFactory::createOne(['mainCategory' => $category, 'slug' => 'competing-slug']);
        $this->commitRow($this->entityManager, 'catalog_product', [
            'id' => $competing->id()->toString(),
            'slug' => 'competing-slug',
            'name' => 'Competing',
            'short_description' => '',
            'primary_category_id' => $category->id()->toString(),
            'tax_class' => 'standard',
            'images' => '[]',
            'variant_axes' => '[]',
            'version' => '1',
            'status' => 'published',
            'updated_at' => '2026-11-07 08:00:00+00',
        ]);
        $this->commitRow($this->entityManager, 'catalog_product_variant', [
            'id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'product_id' => $competing->id()->toString(),
            'position' => '0',
            'option_values' => '[]',
        ]);

        try {
            $this->products->save(ProductFactory::createOne(['mainCategory' => $category, 'variants' => [['id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'values' => []]]]), null, null, new \DateTimeImmutable());
            self::fail('Expected VariantAlreadyExists.');
        } catch (VariantAlreadyExists) {
        }
    }

    /**
     * Pairs that lower() of PostgreSQL maps to the same text, but mb_strtolower of the domain does not.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function valuesTheDomainTellsApart(): iterable
    {
        yield 'capital I with dot above and i' => ['İ', 'i'];
        yield 'capital sigma at the end of a word and small sigma' => ['ΑΣ', 'ασ'];
    }

    #[DataProvider('valuesTheDomainTellsApart')]
    public function testCombinationsTheDomainTellsApartAreSaved(string $first, string $second): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'variantAxes' => ['Wzór'], 'variants' => [
            ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => [$first]],
            ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'values' => [$second]],
        ]]);

        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        self::assertSame([$first, $second], array_map(static fn (ProductVariant $v): string => $v->label(), $this->products->get($product->id())->variants()->toList()));
    }

    #[DataProvider('valuesTheDomainTellsApart')]
    public function testReplacingVariantsWithCombinationsTheDomainTellsApartIsSaved(string $first, string $second): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'variantAxes' => ['Wzór'], 'variants' => [
            ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => [$second]],
        ]]);
        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        $this->products->save(ProductFactory::createOne([
            'id' => $product->id()->toString(),
            'slug' => $product->slug()->value(),
            'mainCategory' => $main,
            'variantAxes' => ['Wzór'],
            'variants' => [
                ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'values' => [$first]],
                ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => [$second]],
            ],
        ]), null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        self::assertSame([$first, $second], array_map(static fn (ProductVariant $v): string => $v->label(), $this->products->get($product->id())->variants()->toList()));
    }

    public function testValuesKeyIsTheComparisonKeyOfTheDomain(): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'variantAxes' => ['Kolor', 'Rozmiar'], 'variants' => [
            ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => ['Żółty', 'XL']],
            ['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'values' => ['İ', '1/2']],
        ]]);
        $this->products->save($product, null, null, new \DateTimeImmutable());

        self::assertSame(
            ['["żółty","xl"]', "[\"i\u{307}\",\"1/2\"]"],
            $this->connection->fetchFirstColumn('SELECT values_key FROM catalog_product_variant WHERE product_id = ? ORDER BY position', [$product->id()->toString()]),
        );
    }

    public function testDatabaseRejectsARepeatedValuesKeyInOneProduct(): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'variantAxes' => ['Kolor'], 'variants' => [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => ['Czarny']]]]);
        $this->products->save($product, null, null, new \DateTimeImmutable());

        try {
            $this->connection->executeStatement(
                'INSERT INTO catalog_product_variant (id, product_id, position, option_values, values_key) VALUES (?, ?, 1, CAST(? AS jsonb), ?)',
                ['bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $product->id()->toString(), '["CZARNY"]', '["czarny"]'],
            );
            self::fail('Expected a unique violation.');
        } catch (UniqueConstraintViolationException $e) {
            self::assertStringContainsString('catalog_product_variant_values_key', $e->getMessage());
        }
    }

    /**
     * What the previous version of the code writes while a deployment replaces it: the index does not check rows
     * without a key, the domain does (under the version of the aggregate).
     */
    public function testVariantRowsWithoutAValuesKeyAreNotCheckedByTheIndex(): void
    {
        [$main] = $this->savedCategories(1);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'variantAxes' => ['Kolor'], 'variants' => [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => ['Czarny']]]]);
        $this->products->save($product, null, null, new \DateTimeImmutable());

        $this->insertVariantRow($product, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 1, '["biały"]');
        $this->insertVariantRow($product, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 2, '["biały"]');

        self::assertSame(
            ['["czarny"]', null, null],
            $this->connection->fetchFirstColumn('SELECT values_key FROM catalog_product_variant WHERE product_id = ? ORDER BY position', [$product->id()->toString()]),
        );
    }

    public function testDatabaseAcceptsTheSameCombinationInAnotherProductButNotTheSamePosition(): void
    {
        [$main] = $this->savedCategories(1);
        $first = ProductFactory::createOne(['mainCategory' => $main, 'variantAxes' => ['Kolor'], 'variants' => [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'values' => ['czarny']]]]);
        $second = ProductFactory::createOne(['mainCategory' => $main, 'variantAxes' => ['Kolor'], 'variants' => [['id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'values' => ['czarny']]]]);
        $this->products->save($first, null, null, new \DateTimeImmutable());
        $this->products->save($second, null, null, new \DateTimeImmutable());

        $this->expectException(UniqueConstraintViolationException::class);

        $this->insertVariantRow($first, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 0, '["biały"]');
    }

    private function insertVariantRow(Product $product, string $id, int $position, string $optionValues): void
    {
        $this->connection->executeStatement(
            'INSERT INTO catalog_product_variant (id, product_id, position, option_values) VALUES (?, ?, ?, CAST(? AS jsonb))',
            [$id, $product->id()->toString(), $position, $optionValues],
        );
    }

    /**
     * @return list<Category>
     */
    private function savedCategories(int $count): array
    {
        $categories = CategoryFactory::createMany($count);
        foreach ($categories as $category) {
            $this->categories->save($category);
        }

        return $categories;
    }

    /**
     * @return list<string>
     */
    private function assignedCategoryIds(Product $product): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT category_id FROM catalog_product_category WHERE product_id = ? ORDER BY category_id',
            [$product->id()->toString()],
        );
    }
}
