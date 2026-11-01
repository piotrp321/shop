<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Catalog;

use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Application\Port\CatalogProduct;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Infrastructure\Catalog\QueryBusCatalogProducts;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Pricing's adapter of the Catalog queries, through the real query bus and shop_test.
 */
final class QueryBusCatalogProductsTest extends KernelTestCase
{
    use Factories;

    private QueryBusCatalogProducts $catalog;

    protected function setUp(): void
    {
        $queryBus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $queryBus);
        $this->catalog = new QueryBusCatalogProducts($queryBus);
    }

    public function testTaxClassOfACatalogProduct(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'taxClass' => TaxClassCode::EnergyGel]);
        new DoctrineProductRepository($entityManager)->save($product);

        $taxClass = $this->catalog->taxClassOf(ProductId::fromString($product->id()->toString()));

        self::assertSame('energy_gel', $taxClass?->toString());
    }

    public function testUnknownProductHasNoTaxClass(): void
    {
        self::assertNull($this->catalog->taxClassOf(ProductId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60')));
    }

    public function testPagesThroughTheCatalogProducts(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $expected = [];
        foreach ([TaxClassCode::Standard, TaxClassCode::EnergyBar, TaxClassCode::PrintedBook] as $taxClass) {
            $product = ProductFactory::createOne(['mainCategory' => $category, 'taxClass' => $taxClass]);
            new DoctrineProductRepository($entityManager)->save($product);
            $expected[] = [$product->id()->toString(), $product->slug()->value(), $taxClass->value];
        }
        sort($expected);

        $first = $this->catalog->page(null, 2);
        $second = $this->catalog->page($first[1]->id, 2);

        self::assertSame($expected, array_map(
            static fn (CatalogProduct $product): array => [$product->id->toString(), $product->slug, $product->taxClass->toString()],
            [...$first, ...$second],
        ));
        self::assertSame([], $this->catalog->page($second[0]->id, 2));
    }
}
