<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Catalog;

use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Application\Port\CatalogVariant;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Infrastructure\Catalog\QueryBusCatalogVariants;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Pricing's adapter of the Catalog variant queries, through the real query bus and shop_test. The variants never
 * have the ids of their products.
 */
final class QueryBusCatalogVariantsTest extends KernelTestCase
{
    use Factories;

    private QueryBusCatalogVariants $catalog;

    protected function setUp(): void
    {
        $queryBus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $queryBus);
        $this->catalog = new QueryBusCatalogVariants($queryBus);
    }

    public function testTaxClassOfAVariantIsTheClassOfItsProduct(): void
    {
        $product = $this->saveProduct(TaxClassCode::EnergyGel, ['Smak'], [
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'values' => ['cytryna']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'values' => ['malina']],
        ]);

        self::assertSame('energy_gel', $this->catalog->taxClassOf(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02'))?->toString());
        self::assertNull($this->catalog->taxClassOf(VariantId::fromString($product->id()->toString())), 'a product id is not a variant id');
    }

    public function testUnknownVariantHasNoTaxClass(): void
    {
        self::assertNull($this->catalog->taxClassOf(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60')));
    }

    public function testPagesThroughTheCatalogVariantsWithTheirKeysAndClasses(): void
    {
        $expected = [];
        foreach ([TaxClassCode::Standard, TaxClassCode::EnergyBar] as $taxClass) {
            $product = $this->saveProduct($taxClass);
            $expected[] = [$product->variants()->toList()[0]->id()->toString(), $product->slug()->value().'/standard', $taxClass->value];
        }
        $book = $this->saveProduct(TaxClassCode::PrintedBook, ['Oprawa'], [
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03', 'values' => ['oprawa twarda']],
        ]);
        $expected[] = ['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03', $book->slug()->value().'/oprawa-twarda', 'printed_book'];
        sort($expected);

        $first = $this->catalog->page(null, 2);
        $second = $this->catalog->page($first[1]->id, 2);

        self::assertSame($expected, array_map(
            static fn (CatalogVariant $variant): array => [$variant->id->toString(), $variant->key, $variant->taxClass->toString()],
            [...$first, ...$second],
        ));
        self::assertSame([], $this->catalog->page($second[0]->id, 2));
    }

    /**
     * @param list<string>                                  $axes
     * @param list<array{id: string, values: list<string>}> $variants none for the factory's default variant
     */
    private function saveProduct(TaxClassCode $taxClass, array $axes = [], array $variants = []): Product
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $attributes = ['mainCategory' => $category, 'taxClass' => $taxClass];
        if ([] !== $variants) {
            $attributes += ['variantAxes' => $axes, 'variants' => $variants];
        }
        $product = ProductFactory::createOne($attributes);
        new DoctrineProductRepository($entityManager)->save($product, null, null, new \DateTimeImmutable());

        return $product;
    }
}
