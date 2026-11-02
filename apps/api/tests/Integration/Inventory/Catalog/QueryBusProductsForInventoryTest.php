<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Catalog;

use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Inventory\Application\Port\ProductsForInventory;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Infrastructure\Catalog\QueryBusProductsForInventory;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Inventory's adapter of the Catalog identities query, through the real query bus and shop_test.
 */
final class QueryBusProductsForInventoryTest extends KernelTestCase
{
    use Factories;

    private QueryBusProductsForInventory $products;

    protected function setUp(): void
    {
        $queryBus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $queryBus);
        $this->products = new QueryBusProductsForInventory($queryBus);
    }

    public function testPagesThroughTheCatalogInInventoryTerms(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $slugs = [];
        foreach (['inventory-page-a', 'inventory-page-b'] as $slug) {
            $product = ProductFactory::createOne(['mainCategory' => $category, 'slug' => $slug]);
            new DoctrineProductRepository($entityManager)->save($product);
            $slugs[$product->id()->toString()] = $slug;
        }
        ksort($slugs);
        $ids = array_keys($slugs);

        $page = $this->products->page(null, 1000);
        $found = [];
        foreach ($page as $product) {
            if (isset($slugs[$product->id->toString()])) {
                $found[$product->id->toString()] = $product->slug;
            }
        }
        self::assertSame($slugs, $found);

        $afterFirst = array_map(static fn ($product): string => $product->id->toString(), $this->products->page(ProductId::fromString($ids[0]), 1000));
        self::assertNotContains($ids[0], $afterFirst);
        self::assertContains($ids[1], $afterFirst);
        self::assertCount(1, $this->products->page(null, 1));
    }

    public function testItIsThePortOfTheApplication(): void
    {
        self::assertInstanceOf(QueryBusProductsForInventory::class, self::getContainer()->get(ProductsForInventory::class));
    }
}
