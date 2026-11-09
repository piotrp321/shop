<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Demo;

use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Application\Demo\DemoDiscountCodeSeeder;
use App\Pricing\Application\Demo\DemoPriceSeeder;
use App\Pricing\Application\Demo\DemoPromotionSeeder;
use App\Pricing\Application\Query\GetCurrentPrices;
use App\Pricing\Application\Query\PriceSnapshot;
use App\Pricing\Infrastructure\Demo\DbalPricingReset;
use App\Pricing\Infrastructure\Demo\YamlDemoDiscountCodeSource;
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
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
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
    private ?string $codesFile = null;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->promotionsFile, $this->codesFile] as $file) {
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
        self::assertEqualsWithDelta(30, is_numeric($promotion['days']) ? self::days($promotion['days']) : -1.0, 0.01);
        self::assertSame([$tent], $this->connection->fetchFirstColumn('SELECT variant_id FROM pricing_promotion_variant WHERE promotion_id = ?', [$promotion['id']]));
    }

    public function testAfterTheSeedAPromotedVariantHasThePromotionalPriceAndTheCatalogPriceAsItsOmnibusPrice(): void
    {
        $tent = $this->catalogVariants('namiot-turystyczny-norvane-biwak-2-zielony', TaxClassCode::Standard)[0];
        $tester = $this->tester("\"namiot-*\": 100000\n", "- name: Namioty\n  percentOff: 20\n  days: 30\n  variants: [\"namiot-*\"]\n");
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        // A promotion from now starts at the start of the next second.
        usleep(1_200_000);

        $queryBus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $queryBus);
        $stamp = $queryBus->dispatch(new GetCurrentPrices([$tent], true))->last(HandledStamp::class);
        self::assertInstanceOf(HandledStamp::class, $stamp);
        $snapshots = $stamp->getResult();
        self::assertIsArray($snapshots);
        $snapshot = $snapshots[0] ?? null;

        self::assertInstanceOf(PriceSnapshot::class, $snapshot);
        self::assertSame(80000, $snapshot->gross, 'the promotional price');
        self::assertSame(100000, $snapshot->regularGross);
        self::assertSame(100000, $snapshot->promotion?->lowestPriorGross, 'the history has the seed entry only: the Omnibus price is the catalog price');
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

    public function testTheDiscountCodesAreCreatedWithoutAnActorWithTheirPeriodsRelativeToTheSeed(): void
    {
        $this->catalogVariants('namiot-turystyczny-norvane-biwak-2-zielony', TaxClassCode::Standard);

        $tester = $this->tester("\"namiot-*\": 100000\n", codes: <<<'YAML'
            - code: witaj10
              percentOff: 10
              startsInDays: 0
            - code: MINUS50
              amountOff: 5000
              minimumOrder: 30000
              startsInDays: 0
            - code: LATO15
              percentOff: 15
              startsInDays: -60
              endsInDays: -30
            YAML);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('discount codes: 3', $tester->getDisplay());
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT code, kind, percent_off, amount_gross, minimum_order_gross, usage_limit, enabled, created_by_id, created_by_name,
                   EXTRACT(EPOCH FROM NOW() - starts_at) / 86400 AS started_days_ago,
                   EXTRACT(EPOCH FROM NOW() - ends_at) / 86400 AS ended_days_ago
            FROM pricing_discount_code ORDER BY code
            SQL);
        self::assertSame(['LATO15', 'MINUS50', 'WITAJ10'], array_column($rows, 'code'));
        [$summer, $minus, $welcome] = $rows;
        self::assertSame(['percent', 15, null, null, null, true, null, null], [$summer['kind'], $summer['percent_off'], $summer['amount_gross'], $summer['minimum_order_gross'], $summer['usage_limit'], $summer['enabled'], $summer['created_by_id'], $summer['created_by_name']]);
        self::assertSame(['amount', null, 5000, 30000], [$minus['kind'], $minus['percent_off'], $minus['amount_gross'], $minus['minimum_order_gross']]);
        self::assertSame(['percent', 10, null], [$welcome['kind'], $welcome['percent_off'], $welcome['ended_days_ago']]);
        self::assertEqualsWithDelta(60.0, self::days($summer['started_days_ago']), 0.01);
        self::assertEqualsWithDelta(30.0, self::days($summer['ended_days_ago']), 0.01);
        self::assertEqualsWithDelta(0.0, self::days($minus['started_days_ago']), 0.01);
        self::assertNull($minus['ended_days_ago']);
    }

    public function testInvalidDiscountCodesFileFailsAndLeavesPricesPromotionsAndCodesUnchanged(): void
    {
        $tent = $this->catalogVariants('namiot-turystyczny-norvane-biwak-2-zielony', TaxClassCode::Standard)[0];
        $this->tester("\"namiot-*\": 89900\n", codes: "- code: WITAJ10\n  percentOff: 10\n  startsInDays: 0\n")->execute([]);
        self::assertSame(['WITAJ10'], $this->connection->fetchFirstColumn('SELECT code FROM pricing_discount_code'));

        $tester = $this->tester("\"namiot-*\": 99900\n", codes: "- code: NOWY10\n  percentOff: 10\n  startsInDays: 0\n- code: MINUS50\n  amountOff: 5000\n  startsInDays: 0\n");

        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('MINUS50', $display);
        self::assertStringContainsString('Nothing was changed', $display);
        self::assertSame(89900, $this->price($tent)['gross_amount']);
        self::assertSame(['WITAJ10'], $this->connection->fetchFirstColumn('SELECT code FROM pricing_discount_code'));
    }

    public function testASecondSeedReplacesTheCodesAndTheirUsages(): void
    {
        $this->catalogVariants('namiot-turystyczny-norvane-biwak-2-zielony', TaxClassCode::Standard);
        $codes = "- code: WITAJ10\n  percentOff: 10\n  startsInDays: 0\n";
        $this->tester("\"namiot-*\": 100000\n", codes: $codes)->execute([]);
        $this->connection->executeStatement(
            "INSERT INTO pricing_discount_code_usage (id, code_id, order_id, used_at) SELECT '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a61', id, '0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a62', NOW() FROM pricing_discount_code",
        );

        $tester = $this->tester("\"namiot-*\": 100000\n", codes: $codes);

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_discount_code'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_discount_code_usage'));
    }

    private static function days(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : -1.0;
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

    private function tester(string $prices, string $promotions = "- name: Namioty\n  percentOff: 20\n  days: 30\n  variants: [\"namiot-*\"]\n", string $codes = "- code: WITAJ10\n  percentOff: 10\n  startsInDays: 0\n"): CommandTester
    {
        $this->file ??= tempnam(sys_get_temp_dir(), 'prices') ?: null;
        $this->promotionsFile ??= tempnam(sys_get_temp_dir(), 'promotions') ?: null;
        self::assertIsString($this->file);
        self::assertIsString($this->promotionsFile);
        file_put_contents($this->file, $prices);
        file_put_contents($this->promotionsFile, $promotions);
        $this->codesFile ??= tempnam(sys_get_temp_dir(), 'codes') ?: null;
        self::assertIsString($this->codesFile);
        file_put_contents($this->codesFile, $codes);
        $kernel = self::bootKernel();
        $seeder = self::getContainer()->get(DemoPriceSeeder::class);
        self::assertInstanceOf(DemoPriceSeeder::class, $seeder);
        $promotionSeeder = self::getContainer()->get(DemoPromotionSeeder::class);
        self::assertInstanceOf(DemoPromotionSeeder::class, $promotionSeeder);
        $codeSeeder = self::getContainer()->get(DemoDiscountCodeSeeder::class);
        self::assertInstanceOf(DemoDiscountCodeSeeder::class, $codeSeeder);
        $application = new Application($kernel);
        $application->addCommand(new SeedDemoPricesCommand(new YamlDemoPriceSource($this->file), $seeder, new YamlDemoPromotionSource($this->promotionsFile), $promotionSeeder, new YamlDemoDiscountCodeSource($this->codesFile), $codeSeeder, new DbalPricingReset($this->connection), 'test'));

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
