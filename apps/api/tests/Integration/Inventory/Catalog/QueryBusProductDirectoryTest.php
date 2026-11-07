<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Catalog;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Inventory\Application\Port\CategoryNotFound;
use App\Inventory\Application\Port\DirectoryProduct;
use App\Inventory\Application\Port\DirectoryVariant;
use App\Inventory\Application\Port\InvalidStockListCursor;
use App\Inventory\Application\Port\ProductDirectory;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Infrastructure\Catalog\QueryBusProductDirectory;
use App\Shared\Domain\Exception\InvalidInput;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Inventory's directory of Catalog products and their variants, through the real query bus and shop_test. The
 * variants never have the ids of their products.
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

    public function testVariantsOfTheProductsWithTheirLabelsInPositionOrderKeyedByProduct(): void
    {
        $category = $this->saveCategory();
        $kayak = ProductFactory::createOne([
            'mainCategory' => $category,
            'variantAxes' => ['Kolor'],
            'variants' => [
                ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'values' => ['zielony']],
                ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'values' => ['pomarańczowy']],
            ],
        ]);
        new DoctrineProductRepository($this->entityManager)->save($kayak, null, null, new \DateTimeImmutable());
        $gel = $this->saveProduct($category, 'Żel');
        $unknown = ProductId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5aff');

        $variants = $this->directory->variantsOf([ProductId::fromString($gel->id()->toString()), $unknown, ProductId::fromString($kayak->id()->toString())]);

        $summary = array_map(
            static fn (array $ofProduct): array => array_map(static fn (DirectoryVariant $variant): array => [$variant->id->toString(), $variant->label], $ofProduct),
            $variants,
        );
        $gelVariant = $gel->variants()->toList()[0]->id()->toString();
        self::assertNotSame($gel->id()->toString(), $gelVariant);
        self::assertSame(
            [
                $gel->id()->toString() => [[$gelVariant, '']],
                $kayak->id()->toString() => [['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'zielony'], ['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'pomarańczowy']],
            ],
            $summary,
        );
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
        new DoctrineProductRepository($this->entityManager)->save($product, null, null, new \DateTimeImmutable());

        return $product;
    }
}
