<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Demo;

use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Application\Demo\DemoPriceSeeder;
use App\Pricing\Infrastructure\Demo\DbalPricingReset;
use App\Pricing\Infrastructure\Demo\YamlDemoPriceSource;
use App\Pricing\UI\Console\SeedDemoPricesCommand;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * pricing:seed-demo on shop_test (rolled back after the test): variants from Catalog through the query bus,
 * prices through SetVariantPrice on the command bus.
 */
final class SeedDemoPricesTest extends KernelTestCase
{
    use Factories;

    private Connection $connection;
    private ?string $file = null;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
    }

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    public function testEveryVariantGetsASeedPriceMatchedByItsKeyAtTheRateOfItsClass(): void
    {
        $tent = $this->catalogVariants('namiot-turystyczny-norvane-biwak-2-zielony', TaxClassCode::Standard)[0];
        [$soft, $hard] = $this->catalogVariants('przewodnik-tarvik-press-tatry', TaxClassCode::PrintedBook, ['Oprawa'], [['oprawa miękka'], ['oprawa twarda']]);
        // A price from an earlier seed is removed with its history.
        $this->connection->executeStatement(
            "INSERT INTO pricing_price_history (id, variant_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, changed_at, source) VALUES ('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', ?, 'PLN', 100, 81, 19, 2300, 'standard', NOW(), 'staff')",
            [$tent],
        );

        $tester = $this->tester("\"namiot-*/standard\": 89900\n\"przewodnik-*/oprawa-twarda\": 5990\n\"przewodnik-*\": 4490\n");

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('prices: 3', $tester->getDisplay());
        self::assertSame(
            [
                ['gross_amount' => 89900, 'vat_rate_bp' => 2300, 'tax_class' => 'standard', 'version' => 1],
                ['gross_amount' => 4490, 'vat_rate_bp' => 500, 'tax_class' => 'printed_book', 'version' => 1],
                ['gross_amount' => 5990, 'vat_rate_bp' => 500, 'tax_class' => 'printed_book', 'version' => 1],
            ],
            [$this->price($tent), $this->price($soft), $this->price($hard)],
        );
        self::assertEqualsCanonicalizing(
            [[$tent, 'seed', null, null], [$soft, 'seed', null, null], [$hard, 'seed', null, null]],
            array_map(
                static fn (array $row): array => [$row['variant_id'], $row['source'], $row['actor_id'], $row['actor_name']],
                $this->connection->fetchAllAssociative('SELECT variant_id, source, actor_id, actor_name FROM pricing_price_history'),
            ),
        );
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_variant_price p JOIN catalog_product c ON c.id = p.variant_id'), 'no price is keyed by a product id');
    }

    public function testVariantWithoutPatternFailsAndLeavesThePricesUnchanged(): void
    {
        $tent = $this->catalogVariants('namiot-turystyczny-norvane-biwak-2-zielony', TaxClassCode::Standard)[0];
        $this->tester("\"namiot-*\": 89900\n")->execute([]);
        $this->catalogVariants('kajak-turystyczny-vilmar-rzeka', TaxClassCode::Standard, ['Kolor'], [['zielony'], ['żółty']]);

        $tester = $this->tester("\"namiot-*\": 99900\n\"kajak-*/zielony\": 199900\n");

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('kajak-turystyczny-vilmar-rzeka/zolty', $display);
        self::assertStringNotContainsString('kajak-turystyczny-vilmar-rzeka/zielony', $display);
        self::assertSame(89900, $this->price($tent)['gross_amount']);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_variant_price'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_price_history'));
    }

    /**
     * @return array<string, mixed>
     */
    private function price(string $variantId): array
    {
        $row = $this->connection->fetchAssociative('SELECT gross_amount, vat_rate_bp, tax_class, version FROM pricing_variant_price WHERE variant_id = ?', [$variantId]);
        self::assertIsArray($row);

        return $row;
    }

    private function tester(string $prices): CommandTester
    {
        $this->file ??= tempnam(sys_get_temp_dir(), 'prices') ?: null;
        self::assertIsString($this->file);
        file_put_contents($this->file, $prices);
        $kernel = self::bootKernel();
        $seeder = self::getContainer()->get(DemoPriceSeeder::class);
        self::assertInstanceOf(DemoPriceSeeder::class, $seeder);
        $application = new Application($kernel);
        $application->addCommand(new SeedDemoPricesCommand(new YamlDemoPriceSource($this->file), $seeder, new DbalPricingReset($this->connection), 'test'));

        return new CommandTester($application->find('pricing:seed-demo'));
    }

    /**
     * @param list<string>       $axes
     * @param list<list<string>> $values the values of each variant; none for one default variant
     *
     * @return list<string> the ids of the variants (never the id of the product), in the order given
     */
    private function catalogVariants(string $slug, TaxClassCode $taxClass, array $axes = [], array $values = []): array
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $attributes = ['mainCategory' => $category, 'slug' => $slug, 'taxClass' => $taxClass];
        if ([] !== $values) {
            $attributes['variantAxes'] = $axes;
            $attributes['variants'] = array_map(static fn (array $variantValues): array => ['id' => (string) Uuid::v4(), 'values' => $variantValues], $values);
        }
        $product = ProductFactory::createOne($attributes);
        new DoctrineProductRepository($entityManager)->save($product, null, null, new \DateTimeImmutable());

        return array_map(static fn (ProductVariant $variant): string => $variant->id()->toString(), $product->variants()->toList());
    }
}
