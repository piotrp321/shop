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
use App\Tests\Support\StatementCounter;
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

    /**
     * The index gets the effective price: a variant in an active promotion has its promotional gross, and the "from"
     * price follows it. The offers are read without the Omnibus price, so neither the history nor the earlier
     * promotions are read.
     */
    public function testTheFactsHaveTheEffectivePriceOfAPromotedVariantWithoutReadingTheOmnibusInput(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $promoted = (string) Uuid::v4();
        $regular = (string) Uuid::v4();
        $product = ProductFactory::createOne(['mainCategory' => $category, 'variantAxes' => ['Kolor'], 'variants' => [
            ['id' => $regular, 'values' => ['czarne']],
            ['id' => $promoted, 'values' => ['białe']],
        ]]);
        new DoctrineProductRepository($entityManager)->save($product, null, null, new \DateTimeImmutable());
        VariantPriceRecordFactory::store($entityManager, ['variantId' => $regular, 'gross' => 4_900]);
        VariantPriceRecordFactory::store($entityManager, ['variantId' => $promoted, 'gross' => 5_000]);
        StockFixture::stock($entityManager->getConnection(), 5, variantId: $regular);
        StockFixture::stock($entityManager->getConnection(), 5, variantId: $promoted);
        $promotion = (string) Uuid::v7();
        $entityManager->getConnection()->executeStatement(
            "INSERT INTO pricing_promotion (id, name, percent_off, starts_at, ends_at, created_at, updated_at, version) VALUES (?, 'Promocja', 20, date_trunc('second', NOW()) - INTERVAL '1 hour', date_trunc('second', NOW()) + INTERVAL '1 day', NOW(), NOW(), 1)",
            [$promotion],
        );
        $entityManager->getConnection()->executeStatement('INSERT INTO pricing_promotion_variant (promotion_id, variant_id) VALUES (?, ?)', [$promotion, $promoted]);
        $entityManager->clear();
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);

        $before = $counter->count();
        $facts = new QueryBusProductOfferFacts($bus)->of([$product->id()->toString()]);
        $statements = $counter->count() - $before;

        self::assertEquals(new ProductFacts(4_000, [
            $regular => new VariantFacts(4_900, true),
            $promoted => new VariantFacts(4_000, true),
        ]), $facts[$product->id()->toString()]);
        // Catalog's variants, the prices, the active promotions and the stock; no history, no earlier promotions.
        self::assertSame(4, $statements);
    }
}
