<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Demo;

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
use Zenstruck\Foundry\Test\Factories;

/**
 * pricing:seed-demo on shop_test (rolled back after the test): products from Catalog through the query bus,
 * prices through SetProductPrice on the command bus.
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

    public function testEveryProductGetsASeedPriceAtTheRateOfItsClass(): void
    {
        $tent = $this->catalogProduct('namiot-turystyczny-norvane-biwak-2-zielony', TaxClassCode::Standard);
        $map = $this->catalogProduct('mapa-turystyczna-tarvik-press-gorce-1-40-000-skladana', TaxClassCode::PrintedMap);
        // A price from an earlier seed is removed with its history.
        $this->connection->executeStatement(
            "INSERT INTO pricing_price_history (id, product_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, changed_at, source) VALUES ('0192f0c4-7c1a-7c4e-9a6e-3f1d2b4c5a60', ?, 'PLN', 100, 81, 19, 2300, 'standard', NOW(), 'staff')",
            [$tent],
        );

        $tester = $this->tester("\"namiot-*\": 89900\n\"mapa-*\": 2490\n");

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('prices: 2', $tester->getDisplay());
        self::assertSame(
            [
                ['gross_amount' => 89900, 'vat_rate_bp' => 2300, 'tax_class' => 'standard', 'version' => 1],
                ['gross_amount' => 2490, 'vat_rate_bp' => 500, 'tax_class' => 'printed_map', 'version' => 1],
            ],
            [$this->price($tent), $this->price($map)],
        );
        self::assertSame(
            [[$tent, 'seed', null, null], [$map, 'seed', null, null]],
            array_map(
                static fn (array $row): array => [$row['product_id'], $row['source'], $row['actor_id'], $row['actor_name']],
                $this->connection->fetchAllAssociative('SELECT product_id, source, actor_id, actor_name FROM pricing_price_history WHERE product_id IN (?, ?) ORDER BY gross_amount DESC', [$tent, $map]),
            ),
        );
    }

    public function testProductWithoutPatternFailsAndLeavesThePricesUnchanged(): void
    {
        $tent = $this->catalogProduct('namiot-turystyczny-norvane-biwak-2-zielony', TaxClassCode::Standard);
        $this->tester("\"namiot-*\": 89900\n")->execute([]);
        $this->catalogProduct('kajak-turystyczny-vilmar-rzeka-1-zielony', TaxClassCode::Standard);

        $tester = $this->tester("\"namiot-*\": 99900\n");

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('kajak-turystyczny-vilmar-rzeka-1-zielony', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(89900, $this->price($tent)['gross_amount']);
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_price_history'));
    }

    /**
     * @return array<string, mixed>
     */
    private function price(string $productId): array
    {
        $row = $this->connection->fetchAssociative('SELECT gross_amount, vat_rate_bp, tax_class, version FROM pricing_product_price WHERE product_id = ?', [$productId]);
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

    private function catalogProduct(string $slug, TaxClassCode $taxClass): string
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'slug' => $slug, 'taxClass' => $taxClass]);
        new DoctrineProductRepository($entityManager)->save($product);

        return $product->id()->toString();
    }
}
