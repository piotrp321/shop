<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Demo;

use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceChangeOrigin;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Infrastructure\Demo\DbalPricingReset;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineVariantPriceRepository;
use App\Tests\Support\InMemory\RecordingDomainEventPublisher;
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
        $prices = new DoctrineVariantPriceRepository($entityManager, new RecordingDomainEventPublisher());
        foreach ([1000, 2000] as $gross) {
            $price = VariantPrice::create(VariantId::fromString((string) Uuid::v4()), GrossPrice::pln($gross), TaxClass::fromString('standard'), new PolishVatRates(), PriceChangeOrigin::seed());
            $prices->save($price);
            $price->changeGross(GrossPrice::pln($gross + 1), new PolishVatRates(), PriceChangeOrigin::seed());
            $prices->save($price);
        }
        $connection = $entityManager->getConnection();
        self::assertSame(2, $connection->fetchOne('SELECT COUNT(*) FROM pricing_variant_price'));
        self::assertSame(4, $connection->fetchOne('SELECT COUNT(*) FROM pricing_price_history'));

        $reset = new DbalPricingReset($connection);
        $reset->reset();
        $reset->reset();

        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM pricing_variant_price'));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM pricing_price_history'));
    }

    public function testRemovesAllPromotionsAndTheirQueueOnly(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->executeStatement('DELETE FROM pricing_promotion');
        $connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name IN ('promotions', 'scheduled', 'outbox', 'failed')");
        foreach (['0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c7001', '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c7002'] as $id) {
            $connection->executeStatement(
                "INSERT INTO pricing_promotion (id, name, percent_off, starts_at, ends_at, created_at, updated_at, version) VALUES (?, 'Promocja', 15, NOW(), NOW() + interval '30 days', NOW(), NOW(), 1)",
                [$id],
            );
            $connection->executeStatement('INSERT INTO pricing_promotion_variant (promotion_id, variant_id) VALUES (?, gen_random_uuid())', [$id]);
        }
        foreach (['promotions', 'promotions', 'scheduled', 'outbox', 'failed'] as $queue) {
            $connection->executeStatement(
                'INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES (:body, :headers, :queue, NOW(), NOW())',
                ['body' => '{}', 'headers' => '{}', 'queue' => $queue],
            );
        }

        $reset = new DbalPricingReset($connection);
        $reset->reset();
        $reset->reset();

        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM pricing_promotion'));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM pricing_promotion_variant'));
        self::assertSame(0, $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'promotions'"));
        self::assertEqualsCanonicalizing(
            ['scheduled', 'outbox', 'failed'],
            $connection->fetchFirstColumn("SELECT queue_name FROM messenger_messages WHERE queue_name IN ('promotions', 'scheduled', 'outbox', 'failed')"),
            'the other queues are left alone',
        );
    }
}
