<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Demo;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\ProductPrice;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Infrastructure\Demo\DbalPricingReset;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineProductPriceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * PricingReset on shop_test (rolled back after the test).
 */
final class DbalPricingResetTest extends KernelTestCase
{
    public function testRemovesAllPricesAndTheirHistory(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $prices = new DoctrineProductPriceRepository($entityManager);
        foreach ([1000, 2000] as $gross) {
            $price = ProductPrice::create(ProductId::fromString((string) Uuid::v4()), GrossPrice::pln($gross), TaxClass::fromString('standard'), new PolishVatRates(), PriceChangeOrigin::seed());
            $prices->save($price);
            $price->changeGross(GrossPrice::pln($gross + 1), new PolishVatRates(), PriceChangeOrigin::seed());
            $prices->save($price);
        }
        $connection = $entityManager->getConnection();
        self::assertSame(2, $connection->fetchOne('SELECT COUNT(*) FROM pricing_product_price'));
        self::assertSame(4, $connection->fetchOne('SELECT COUNT(*) FROM pricing_price_history'));

        $reset = new DbalPricingReset($connection);
        $reset->reset();
        $reset->reset();

        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM pricing_product_price'));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM pricing_price_history'));
    }
}
