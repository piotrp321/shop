<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Catalog;

use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Inventory\Application\Port\ProductCatalog;
use App\Inventory\Domain\Stock\ProductId;
use App\Inventory\Infrastructure\Catalog\QueryBusProductCatalog;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Inventory's adapter of the Catalog query, through the real query bus and shop_test.
 */
final class QueryBusProductCatalogTest extends KernelTestCase
{
    use Factories;

    private QueryBusProductCatalog $catalog;

    protected function setUp(): void
    {
        $queryBus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $queryBus);
        $this->catalog = new QueryBusProductCatalog($queryBus);
    }

    public function testACatalogProductExists(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category]);
        new DoctrineProductRepository($entityManager)->save($product);

        self::assertTrue($this->catalog->exists(ProductId::fromString($product->id()->toString())));
    }

    public function testAnUnknownProductDoesNotExist(): void
    {
        self::assertFalse($this->catalog->exists(ProductId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60')));
    }

    public function testItIsThePortOfTheApplication(): void
    {
        self::assertInstanceOf(QueryBusProductCatalog::class, self::getContainer()->get(ProductCatalog::class));
    }
}
