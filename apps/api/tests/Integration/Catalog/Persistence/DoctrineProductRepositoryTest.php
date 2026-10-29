<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductAlreadyExists;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\SlugAlreadyTaken;
use App\Catalog\Domain\Product\TaxCategory;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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
            'taxCategory' => TaxCategory::Food,
            'imageAlts' => ['front', 'side', 'back'],
        ]);
        $this->products->save($product);
        $this->entityManager->clear();

        $loaded = $this->products->get($product->id());

        self::assertSame([], $loaded->releaseEvents());
        self::assertSame($product->name()->value(), $loaded->name()->value());
        self::assertSame($product->slug()->value(), $loaded->slug()->value());
        self::assertSame(TaxCategory::Food, $loaded->taxCategory());
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
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$last, $first]]));
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
        $this->products->save($product);

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
        $this->products->save($withAdditional);
        $this->products->save($withoutAdditional);

        self::assertSame([$additional->id()->toString()], $this->assignedCategoryIds($withAdditional));
        self::assertSame([], $this->assignedCategoryIds($withoutAdditional));
    }

    public function testSavingExistingProductReplacesItsAdditionalCategories(): void
    {
        [$main, $kept, $removed, $added] = $this->savedCategories(4);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$kept, $removed]]);
        $this->products->save($product);
        $this->entityManager->clear();

        $this->products->save(ProductFactory::createOne([
            'id' => $product->id()->toString(),
            'slug' => $product->slug()->value(),
            'mainCategory' => $main,
            'additionalCategories' => [$kept, $added],
        ]));
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
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'imageAlts' => ['front']]));
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main]));
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main]));

        self::assertSame(3, $this->products->count());
        self::assertSame(2, $this->products->countWithoutImages());
        self::assertCount(3, [...$this->products->all()]);
    }

    public function testDuplicateSlugThrowsSlugAlreadyTakenAndLeavesNoRows(): void
    {
        [$main, $additional] = $this->savedCategories(2);
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'slug' => 'same-slug']));
        $duplicate = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$additional], 'slug' => 'same-slug']);

        try {
            $this->products->save($duplicate);
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
            'tax_category' => 'standard',
            'images' => '[]',
            'version' => '1',
        ]);

        try {
            $this->products->save($product);
            self::fail('Expected ProductAlreadyExists.');
        } catch (ProductAlreadyExists) {
        }

        self::assertSame('Competing', $this->committedValue($this->entityManager, 'SELECT name FROM catalog_product WHERE id = ?', $product->id()->toString()));
    }

    public function testRepositoryWorksAgainAfterSlugAlreadyTakenOnceServicesAreReset(): void
    {
        [$main] = $this->savedCategories(1);
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'slug' => 'same-slug']));
        try {
            $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'slug' => 'same-slug']));
            self::fail('Expected SlugAlreadyTaken.');
        } catch (SlugAlreadyTaken) {
        }
        self::assertFalse($this->entityManager->isOpen());

        // The kernel resets services between requests and Messenger between messages (kernel.reset).
        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();

        $next = ProductFactory::createOne(['mainCategory' => $main]);
        $this->products->save($next);
        self::assertSame($next->slug()->value(), $this->products->get($next->id())->slug()->value());
    }

    public function testMissingMainCategoryViolatesForeignKey(): void
    {
        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->products->save(ProductFactory::createOne(['mainCategory' => CategoryFactory::createOne()]));
    }

    public function testMissingAdditionalCategoryViolatesForeignKey(): void
    {
        [$main] = $this->savedCategories(1);

        $this->expectException(ForeignKeyConstraintViolationException::class);

        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [CategoryFactory::createOne()]]));
    }

    public function testDeletingProductRemovesItsAssignments(): void
    {
        [$main, $additional] = $this->savedCategories(2);
        $product = ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$additional]]);
        $this->products->save($product);

        $this->connection->executeStatement('DELETE FROM catalog_product WHERE id = ?', [$product->id()->toString()]);

        self::assertSame([], $this->assignedCategoryIds($product));
    }

    public function testAssignedAdditionalCategoryCannotBeDeleted(): void
    {
        [$main, $additional] = $this->savedCategories(2);
        $this->products->save(ProductFactory::createOne(['mainCategory' => $main, 'additionalCategories' => [$additional]]));

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
