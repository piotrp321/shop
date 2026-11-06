<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Inventory\Domain\Stock\AvailabilityLevel;
use App\Inventory\Domain\Stock\StockChangeSource;
use App\Inventory\Domain\Stock\StockLevelChanged;
use App\Pricing\Domain\Price\PriceChangeSource;
use App\Pricing\Domain\Price\VariantPriceChanged;
use App\Shared\Infrastructure\Http\Cache\HttpStorefrontCache;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\InMemory\SpyStorefrontCache;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * The route of the price and stock level events on real infrastructure: outbox (Postgres) → relay → RabbitMQ (JSON,
 * so the enums travel as their values) → the consumers of Pricing and Inventory → StorefrontCache (a spy here).
 */
final class StorefrontInvalidationFlowTest extends MessagingKernelTestCase
{
    use Factories;

    private SpyStorefrontCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new SpyStorefrontCache();
        self::getContainer()->set(HttpStorefrontCache::class, $this->cache);
    }

    public function testAPriceChangeAndALevelChangeInvalidateTheProductOfTheVariant(): void
    {
        [$productId, $variantId] = $this->catalogProduct();
        $this->publisher()->publish(
            new VariantPriceChanged($variantId, PriceChangeSource::Staff, new \DateTimeImmutable()),
            new StockLevelChanged($variantId, AvailabilityLevel::LowStock, StockChangeSource::System, new \DateTimeImmutable()),
        );

        $this->consumeOutboxAndEvents(4);

        self::assertSame(['product:'.$productId, 'product:'.$productId], $this->cache->calls);
        self::assertSame(0, $this->rows('failed'));
    }

    public function testSeedEventsAreAckedWithoutInvalidation(): void
    {
        [, $variantId] = $this->catalogProduct();
        $this->publisher()->publish(
            new VariantPriceChanged($variantId, PriceChangeSource::Seed, new \DateTimeImmutable()),
            new StockLevelChanged($variantId, AvailabilityLevel::InStock, StockChangeSource::Seed, new \DateTimeImmutable()),
        );

        $this->consumeOutboxAndEvents(4);

        self::assertSame([], $this->cache->calls);
        self::assertSame(0, $this->eventsInQueue());
        self::assertSame(0, $this->rows('failed'));
    }

    /**
     * @return array{string, string} the product id and the id of its first variant
     */
    private function catalogProduct(): array
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category]);
        new DoctrineProductRepository($entityManager)->save($product);

        return [$product->id()->toString(), $product->variants()->toList()[0]->id()->toString()];
    }

    private function consumeOutboxAndEvents(int $messages): void
    {
        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('messenger:consume'));
        $tester->execute(['receivers' => ['outbox', 'events'], '--limit' => $messages, '--time-limit' => 10]);
        $tester->assertCommandIsSuccessful();
    }
}
