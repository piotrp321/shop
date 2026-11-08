<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Demo;

use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Application\Demo\DemoPriceSeeder;
use App\Pricing\Application\Demo\DemoPromotionSeeder;
use App\Pricing\Infrastructure\Demo\DbalPricingReset;
use App\Pricing\Infrastructure\Demo\YamlDemoPriceSource;
use App\Pricing\Infrastructure\Demo\YamlDemoPromotionSource;
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
    private ?string $promotionsFile = null;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->promotionsFile] as $file) {
            if (null !== $file && is_file($file)) {
                unlink($file);
            }
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

    public function testThePromotionsAreSeededWithoutAnActorAfterThePrices(): void
    {
        $tent = $this->catalogVariants('namiot-turystyczny-norvane-biwak-2-zielony', TaxClassCode::Standard)[0];
        $this->catalogVariants('mapa-turystyczna-tarvik-press-gorce', TaxClassCode::Standard);

        $tester = $this->tester("\"namiot-*\": 100000\n\"mapa-*\": 2490\n", "- name: Namioty\n  percentOff: 20\n  days: 30\n  variants: [\"namiot-*\"]\n");

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('promotions: 1', $tester->getDisplay());
        $promotion = $this->connection->fetchAssociative('SELECT id, name, percent_off, created_by_id, created_by_name, updated_by_id, EXTRACT(EPOCH FROM ends_at - starts_at) / 86400 AS days, starts_at <= NOW() + interval \'2 seconds\' AS starts_now FROM pricing_promotion');
        self::assertIsArray($promotion);
        self::assertSame(['Namioty', 20, null, null, null, true], [$promotion['name'], $promotion['percent_off'], $promotion['created_by_id'], $promotion['created_by_name'], $promotion['updated_by_id'], $promotion['starts_now']]);
        self::assertEqualsWithDelta(30, is_numeric($promotion['days']) ? (float) $promotion['days'] : -1.0, 0.01);
        self::assertSame([$tent], $this->connection->fetchFirstColumn('SELECT variant_id FROM pricing_promotion_variant WHERE promotion_id = ?', [$promotion['id']]));
    }

    public function testAPromotionPatternWithoutAVariantFailsAndLeavesThePricesAndPromotionsUnchanged(): void
    {
        $tent = $this->catalogVariants('namiot-turystyczny-norvane-biwak-2-zielony', TaxClassCode::Standard)[0];
        $this->tester("\"namiot-*\": 89900\n", "- name: Namioty\n  percentOff: 20\n  days: 30\n  variants: [\"namiot-*\"]\n")->execute([]);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_promotion'));

        $tester = $this->tester("\"namiot-*\": 99900\n", "- name: Namioty\n  percentOff: 30\n  days: 30\n  variants: [\"namiot-*\"]\n- name: Kajaki\n  percentOff: 10\n  days: 30\n  variants: [\"kajak-*\"]\n");

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('Kajaki', $display);
        self::assertStringContainsString('Nothing was changed', $display);
        self::assertSame(89900, $this->price($tent)['gross_amount']);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_variant_price'));
        self::assertSame([20], $this->connection->fetchFirstColumn('SELECT percent_off FROM pricing_promotion'));
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

    private function tester(string $prices, string $promotions = "- name: Namioty\n  percentOff: 20\n  days: 30\n  variants: [\"namiot-*\"]\n"): CommandTester
    {
        $this->file ??= tempnam(sys_get_temp_dir(), 'prices') ?: null;
        $this->promotionsFile ??= tempnam(sys_get_temp_dir(), 'promotions') ?: null;
        self::assertIsString($this->file);
        self::assertIsString($this->promotionsFile);
        file_put_contents($this->file, $prices);
        file_put_contents($this->promotionsFile, $promotions);
        $kernel = self::bootKernel();
        $seeder = self::getContainer()->get(DemoPriceSeeder::class);
        self::assertInstanceOf(DemoPriceSeeder::class, $seeder);
        $promotionSeeder = self::getContainer()->get(DemoPromotionSeeder::class);
        self::assertInstanceOf(DemoPromotionSeeder::class, $promotionSeeder);
        $application = new Application($kernel);
        $application->addCommand(new SeedDemoPricesCommand(new YamlDemoPriceSource($this->file), $seeder, new YamlDemoPromotionSource($this->promotionsFile), $promotionSeeder, new DbalPricingReset($this->connection), 'test'));

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
