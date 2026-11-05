<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Demo;

use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Inventory\Application\Demo\DemoStockSeeder;
use App\Inventory\Infrastructure\Demo\DbalInventoryReset;
use App\Inventory\Infrastructure\Demo\YamlDemoStockSource;
use App\Inventory\UI\Console\SeedDemoStockCommand;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Integration\Inventory\StockFixture;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * inventory:seed-demo on shop_test (rolled back after the test): variants and their keys from Catalog through the
 * query bus, stocks through ReceiveStock and CreateEmptyStock on the command bus. The variants never have the ids of
 * their products.
 */
final class SeedDemoStockTest extends KernelTestCase
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

    public function testEveryVariantGetsItsStockAndAnythingFromBeforeIsRemoved(): void
    {
        [$tent] = $this->catalogVariants('namiot-turystyczny-norvane-biwak-2-zielony');
        [$map] = $this->catalogVariants('mapa-turystyczna-tarvik-press-gorce-1-40-000-skladana');
        [$green, $orange] = $this->catalogVariants('kajak-turystyczny-vilmar-rzeka-2', ['zielony', 'pomarańczowy']);
        // What an earlier seed or the shop left is removed with its history and reservations.
        $old = StockFixture::stock($this->connection, 99, 4);
        $this->connection->executeStatement("INSERT INTO inventory_reservation (reservation_key, status, created_at, updated_at) VALUES ('order-1', 'active', now(), now())");
        $this->connection->executeStatement('INSERT INTO inventory_reservation_line (reservation_key, variant_id, quantity) VALUES (?, ?, 4)', ['order-1', $old]);

        $tester = $this->tester("\"kajak-*/zielony\": 0\n\"kajak-*\": 3\n\"namiot-*/standard\": 40\n\"mapa-*\": 25\n");

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('stocks: 4', $tester->getDisplay());
        self::assertSame(
            [
                [$green, 0, 0, 0],
                [$orange, 3, 0, 1],
                [$map, 25, 0, 1],
                [$tent, 40, 0, 1],
            ],
            array_map(
                static fn (array $row): array => [$row['variant_id'], $row['physical'], $row['reserved'], $row['version']],
                $this->connection->fetchAllAssociative('SELECT variant_id, physical, reserved, version FROM inventory_stock_item ORDER BY physical'),
            ),
        );
        self::assertSame(
            [
                [$orange, 'receipt', 3, 3, 'seed:'.$orange, 'seed', null, null],
                [$map, 'receipt', 25, 25, 'seed:'.$map, 'seed', null, null],
                [$tent, 'receipt', 40, 40, 'seed:'.$tent, 'seed', null, null],
            ],
            array_map(
                static fn (array $row): array => [$row['variant_id'], $row['type'], $row['physical_delta'], $row['physical_after'], $row['reference'], $row['source'], $row['actor_id'], $row['actor_name']],
                $this->connection->fetchAllAssociative('SELECT * FROM inventory_stock_movement ORDER BY physical_after'),
            ),
            'a stock of 0 has no history',
        );
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_reservation'));
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_reservation_line'));
    }

    public function testVariantWithoutPatternFailsAndLeavesTheDataUnchanged(): void
    {
        [$tent] = $this->catalogVariants('namiot-turystyczny-norvane-biwak-2-zielony');
        $this->tester("\"namiot-*\": 40\n")->execute([]);
        $this->connection->executeStatement("INSERT INTO inventory_reservation (reservation_key, status, created_at, updated_at) VALUES ('order-1', 'active', now(), now())");
        $this->connection->executeStatement('INSERT INTO inventory_reservation_line (reservation_key, variant_id, quantity) VALUES (?, ?, 4)', ['order-1', $tent]);
        $this->catalogVariants('kajak-turystyczny-vilmar-rzeka-1', ['zielony']);

        $tester = $this->tester("\"namiot-*\": 99\n\"kajak-*/standard\": 1\n");

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('kajak-turystyczny-vilmar-rzeka-1/zielony', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame([[$tent, 40, 1]], array_map(
            static fn (array $row): array => [$row['variant_id'], $row['physical'], $row['version']],
            $this->connection->fetchAllAssociative('SELECT variant_id, physical, version FROM inventory_stock_item'),
        ));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_stock_movement'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_reservation'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM inventory_reservation_line'));
    }

    private function tester(string $stock): CommandTester
    {
        $this->file ??= tempnam(sys_get_temp_dir(), 'stock') ?: null;
        self::assertIsString($this->file);
        file_put_contents($this->file, $stock);
        $kernel = self::bootKernel();
        $seeder = self::getContainer()->get(DemoStockSeeder::class);
        self::assertInstanceOf(DemoStockSeeder::class, $seeder);
        $application = new Application($kernel);
        $application->addCommand(new SeedDemoStockCommand(new YamlDemoStockSource($this->file), $seeder, new DbalInventoryReset($this->connection), 'test'));

        return new CommandTester($application->find('inventory:seed-demo'));
    }

    /**
     * @param list<string> $colours values of a colour axis; none for one default variant
     *
     * @return list<string> the ids of the variants of a new product, in the order of the colours
     */
    private function catalogVariants(string $slug, array $colours = []): array
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($category);
        $attributes = ['mainCategory' => $category, 'slug' => $slug];
        if ([] !== $colours) {
            $attributes += ['variantAxes' => ['Kolor'], 'variants' => array_map(static fn (string $colour): array => ['id' => (string) Uuid::v4(), 'values' => [$colour]], $colours)];
        }
        $product = ProductFactory::createOne($attributes);
        new DoctrineProductRepository($entityManager)->save($product);
        $variantIds = array_map(static fn (ProductVariant $variant): string => $variant->id()->toString(), $product->variants()->toList());
        self::assertNotContains($product->id()->toString(), $variantIds);

        return $variantIds;
    }
}
