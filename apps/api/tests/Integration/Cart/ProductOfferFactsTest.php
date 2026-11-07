<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cart;

use App\Cart\Application\Query\GetProductOffers;
use App\Cart\Infrastructure\Catalog\QueryBusProductOfferFacts;
use App\Catalog\Application\Search\ProductFacts;
use App\Catalog\Application\Search\VariantFacts;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\VariantPriceRecordFactory;
use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Catalog's ProductOfferFacts through the Cart adapter on the real Catalog, Pricing and Inventory (shop_test, rolled
 * back after each test).
 */
final class ProductOfferFactsTest extends KernelTestCase
{
    use Factories;

    public function testFactsOfMoreProductsThanOneQueryTakesFollowTheOfferRule(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $products = new DoctrineProductRepository($entityManager);
        $ids = [];
        for ($p = 0; $p < GetProductOffers::MAX_PRODUCTS; ++$p) {
            $product = ProductFactory::createOne(['mainCategory' => $category]);
            $products->save($product, null, null, new \DateTimeImmutable());
            $ids[] = $product->id()->toString();
        }
        $black = (string) Uuid::v4();
        $white = (string) Uuid::v4();
        $green = (string) Uuid::v4();
        $priced = ProductFactory::createOne(['mainCategory' => $category, 'variantAxes' => ['Kolor'], 'variants' => [
            ['id' => $black, 'values' => ['czarne']],
            ['id' => $white, 'values' => ['białe']],
            ['id' => $green, 'values' => ['zielone']],
        ]]);
        $products->save($priced, null, null, new \DateTimeImmutable());
        // Black: priced and in stock (purchasable); white: cheaper but out of stock; green: no price and no stock.
        VariantPriceRecordFactory::store($entityManager, ['variantId' => $black, 'gross' => 4_900]);
        StockFixture::stock($entityManager->getConnection(), 5, variantId: $black);
        VariantPriceRecordFactory::store($entityManager, ['variantId' => $white, 'gross' => 3_900]);
        $unknown = (string) Uuid::v4();
        $entityManager->clear();
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $facts = new QueryBusProductOfferFacts($bus);

        $result = $facts->of([...$ids, $priced->id()->toString(), $unknown]);

        self::assertCount(GetProductOffers::MAX_PRODUCTS + 2, $result);
        self::assertEquals(new ProductFacts(4_900, [
            $black => new VariantFacts(4_900, true),
            $white => new VariantFacts(3_900, false),
            $green => new VariantFacts(null, false),
        ]), $result[$priced->id()->toString()]);
        self::assertNull($result[$ids[0]]->fromGross);
        self::assertCount(1, $result[$ids[0]]->variants);
        self::assertEquals([new VariantFacts(null, false)], array_values($result[$ids[0]]->variants));
        // A product Catalog does not know has no variants.
        self::assertEquals(new ProductFacts(null, []), $result[$unknown]);
    }
}
