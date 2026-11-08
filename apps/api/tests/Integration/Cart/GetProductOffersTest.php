<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cart;

use App\Cart\Application\Query\GetProductOffers;
use App\Cart\Application\View\ProductOfferView;
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
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * GetProductOffers on the query bus with the real Catalog, Pricing and Inventory (shop_test, rolled back after each
 * test): a full page of 48 products with 100 variants each costs a constant number of queries.
 */
final class GetProductOffersTest extends KernelTestCase
{
    use Factories;

    public function testAFullPageOf48ProductsOf100VariantsIsSixQueriesWithTheOmnibusPrice(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $products = [];
        $promoted = null;
        for ($p = 0; $p < GetProductOffers::MAX_PRODUCTS; ++$p) {
            $variants = array_map(static fn (int $n): array => ['id' => (string) Uuid::v4(), 'values' => ['rozmiar '.$n]], range(1, 100));
            $product = ProductFactory::createOne(['mainCategory' => $category, 'variantAxes' => ['Rozmiar'], 'variants' => $variants]);
            new DoctrineProductRepository($entityManager)->save($product, null, null, new \DateTimeImmutable());
            $products[] = $product->id()->toString();
            if (0 === $p) {
                VariantPriceRecordFactory::store($entityManager, ['variantId' => $variants[99]['id'], 'gross' => 4_900]);
                StockFixture::stock($entityManager->getConnection(), 8, variantId: $variants[99]['id']);
                $promoted = $variants[99]['id'];
            }
        }
        $promotion = (string) Uuid::v7();
        $entityManager->getConnection()->executeStatement(
            "INSERT INTO pricing_promotion (id, name, percent_off, starts_at, ends_at, created_at, updated_at, version) VALUES (?, 'Promocja', 10, date_trunc('second', NOW()) - INTERVAL '1 hour', date_trunc('second', NOW()) + INTERVAL '1 day', NOW(), NOW(), 1)",
            [$promotion],
        );
        $entityManager->getConnection()->executeStatement('INSERT INTO pricing_promotion_variant (promotion_id, variant_id) VALUES (?, ?)', [$promotion, $promoted]);
        $entityManager->clear();
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $before = $counter->count();

        $offers = $bus->dispatch(new GetProductOffers($products, withLowestPriorPrice: true))->last(HandledStamp::class)?->getResult();

        self::assertSame(6, $counter->count() - $before, 'variants, prices, active promotions, the history and the earlier promotions of the Omnibus price, and stock: one query each');
        self::assertIsArray($offers);
        self::assertCount(48, $offers);
        self::assertContainsOnlyInstancesOf(ProductOfferView::class, $offers);
        self::assertSame(4_410, $offers[0]->fromGross);
        self::assertSame('in_stock', $offers[0]->level);
        self::assertCount(100, $offers[0]->variants);
        self::assertNull($offers[1]->fromGross);
    }
}
