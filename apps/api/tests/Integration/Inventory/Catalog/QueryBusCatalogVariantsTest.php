<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Catalog;

use App\Catalog\Domain\Product\Product;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Inventory\Application\Port\CatalogVariant;
use App\Inventory\Domain\Stock\VariantId;
use App\Inventory\Infrastructure\Catalog\QueryBusCatalogVariants;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Inventory's adapter of the Catalog variant queries, through the real query bus and shop_test. The variants never
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

    public function testAVariantOfAProductExistsAndTheIdOfTheProductIsNoVariant(): void
    {
        $product = $this->saveProduct(['Kolor'], [
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b01', 'values' => ['zielony']],
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02', 'values' => ['pomarańczowy']],
        ]);

        self::assertTrue($this->catalog->exists(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b02')));
        self::assertFalse($this->catalog->exists(VariantId::fromString($product->id()->toString())), 'a product id is not a variant id');
    }

    public function testAnUnknownVariantDoesNotExist(): void
    {
        self::assertFalse($this->catalog->exists(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60')));
    }

    public function testPagesThroughTheCatalogVariantsWithTheirKeys(): void
    {
        $expected = [];
        foreach ([1, 2] as $ignored) {
            $product = $this->saveProduct();
            $expected[] = [$product->variants()->toList()[0]->id()->toString(), $product->slug()->value().'/standard'];
        }
        $kayak = $this->saveProduct(['Kolor'], [
            ['id' => '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03', 'values' => ['zielony']],
        ]);
        $expected[] = ['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5b03', $kayak->slug()->value().'/zielony'];
        sort($expected);

        $first = $this->catalog->page(null, 2);
        $second = $this->catalog->page($first[1]->id, 2);

        self::assertSame($expected, array_map(
            static fn (CatalogVariant $variant): array => [$variant->id->toString(), $variant->key],
            [...$first, ...$second],
        ));
        self::assertSame([], $this->catalog->page($second[0]->id, 2));
    }

    /**
     * @param list<string>                                  $axes
     * @param list<array{id: string, values: list<string>}> $variants none for the factory's default variant
     */
    private function saveProduct(array $axes = [], array $variants = []): Product
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $attributes = ['mainCategory' => $category];
        if ([] !== $variants) {
            $attributes += ['variantAxes' => $axes, 'variants' => $variants];
        }
        $product = ProductFactory::createOne($attributes);
        new DoctrineProductRepository($entityManager)->save($product, null, null, new \DateTimeImmutable());

        return $product;
    }
}
