<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Catalog;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Inventory\Application\Port\CategoryNotFound;
use App\Inventory\Application\Port\DirectoryProduct;
use App\Inventory\Application\Port\InvalidStockListCursor;
use App\Inventory\Application\Port\ProductDirectory;
use App\Inventory\Infrastructure\Catalog\QueryBusProductDirectory;
use App\Shared\Domain\Exception\InvalidInput;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Inventory's directory of Catalog products, through the real query bus and shop_test.
 */
final class QueryBusProductDirectoryTest extends KernelTestCase
{
    use Factories;

    private QueryBusProductDirectory $directory;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $queryBus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $queryBus);
        $this->directory = new QueryBusProductDirectory($queryBus);
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
    }

    public function testSearchesTheCatalogProductsAsPagesOfInventoryProducts(): void
    {
        $category = $this->saveCategory();
        $this->saveProduct($category, 'Zebra');
        $gel = $this->saveProduct($category, 'Alfa żel');
        $this->saveProduct($category, 'Alfa baton');

        $first = $this->directory->page('alfa', null, null, 1);
        $second = $this->directory->page('alfa', $category->id()->toString(), $first->next, 1);

        self::assertSame(['Alfa baton'], array_map(static fn (DirectoryProduct $product): string => $product->name, $first->items));
        self::assertNotNull($first->next);
        self::assertCount(1, $second->items);
        self::assertSame($gel->id()->toString(), $second->items[0]->id->toString());
        self::assertSame('Alfa żel', $second->items[0]->name);
        self::assertNull($second->next);
    }

    public function testAnUnknownCategoryIsACategoryNotFoundOfInventory(): void
    {
        $this->expectException(CategoryNotFound::class);

        $this->directory->page(null, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', null, 25);
    }

    public function testAMalformedCursorIsAnInvalidCursorOfInventory(): void
    {
        $this->expectException(InvalidStockListCursor::class);

        $this->directory->page(null, null, 'garbage!', 25);
    }

    public function testOutOfRangeParametersAreRejectedAsInvalidInput(): void
    {
        try {
            $this->directory->page('a', null, null, 25);
            self::fail('Expected an InvalidInput exception.');
        } catch (\Throwable $failure) {
            self::assertInstanceOf(InvalidInput::class, $failure);
        }
    }

    public function testItIsThePortOfTheApplication(): void
    {
        self::assertInstanceOf(QueryBusProductDirectory::class, self::getContainer()->get(ProductDirectory::class));
    }

    private function saveCategory(): Category
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);

        return $category;
    }

    private function saveProduct(Category $category, string $name): Product
    {
        $product = ProductFactory::createOne(['mainCategory' => $category, 'name' => $name]);
        new DoctrineProductRepository($this->entityManager)->save($product);

        return $product;
    }
}
