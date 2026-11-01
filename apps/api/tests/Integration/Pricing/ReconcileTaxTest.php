<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing;

use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Pricing\Application\Command\SetProductPrice;
use App\Pricing\Application\TaxReconciliation\ReconcileTax;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\ProductPrice;
use App\Pricing\Domain\Price\ProductPriceRepository;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\VatRate;
use App\Pricing\Infrastructure\Catalog\QueryBusCatalogProducts;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrinePersistenceSession;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineProductPriceRepository;
use App\Pricing\UI\Console\ReconcileTaxCommand;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Factory\ProductPriceRecordFactory;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * pricing:reconcile-tax on shop_test (rolled back after the test), starting from no prices: Catalog through the
 * query bus, prices through Doctrine, a fresh entity manager after a refused save.
 */
final class ReconcileTaxTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $entityManager;
    private Connection $connection;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->connection->executeStatement('DELETE FROM pricing_price_history');
        $this->connection->executeStatement('DELETE FROM pricing_product_price');
    }

    public function testReportsThenRepricesThenFindsNothing(): void
    {
        $book = $this->catalogProduct(TaxClassCode::PrintedBook);
        $tent = $this->catalogProduct(TaxClassCode::Standard);
        $gel = $this->catalogProduct(TaxClassCode::EnergyGel);
        // The class differs from Catalog; the rate is not the current rate of the class; consistent.
        ProductPriceRecordFactory::store($this->entityManager, ['productId' => $book, 'gross' => 5490, 'taxClass' => 'standard', 'vatRateBp' => 2300]);
        ProductPriceRecordFactory::store($this->entityManager, ['productId' => $tent, 'gross' => 89900, 'taxClass' => 'standard', 'vatRateBp' => 800]);
        ProductPriceRecordFactory::store($this->entityManager, ['productId' => $gel, 'gross' => 1290, 'taxClass' => 'energy_gel', 'vatRateBp' => 800]);
        $this->entityManager->clear();
        $tester = $this->containerCommand();

        self::assertSame(1, $tester->execute([]), $tester->getDisplay());
        $display = $this->display($tester);
        self::assertStringContainsString($book.': standard 2300 bp -> printed_book 500 bp: found', $display);
        self::assertStringContainsString($tent.': standard 800 bp -> standard 2300 bp: found', $display);
        self::assertStringNotContainsString($gel, $display);
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_price_history'));
        self::assertSame([1, 1, 1], $this->connection->fetchFirstColumn('SELECT version FROM pricing_product_price'));

        self::assertSame(0, $tester->execute(['--apply' => true]), $tester->getDisplay());
        self::assertStringContainsString('Checked 3 prices: 2 fixed.', $this->display($tester));
        self::assertSame($this->stored(5490, 500, 'printed_book', 2), $this->price($book));
        self::assertSame($this->stored(89900, 2300, 'standard', 2), $this->price($tent));
        self::assertSame($this->stored(1290, 800, 'energy_gel', 1), $this->price($gel));
        self::assertEqualsCanonicalizing(
            [[$book, 'printed_book', 500, 'tax_reconciliation', null], [$tent, 'standard', 2300, 'tax_reconciliation', null]],
            array_map(
                static fn (array $row): array => array_values($row),
                $this->connection->fetchAllAssociative('SELECT product_id, tax_class, vat_rate_bp, source, actor_id FROM pricing_price_history'),
            ),
        );

        self::assertSame(0, $tester->execute(['--apply' => true]), $tester->getDisplay());
        self::assertStringContainsString('Checked 3 prices: all consistent.', $this->display($tester));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM pricing_price_history'));
    }

    /**
     * A staff member changes the gross right before the reconciliation saves: once for the second price, which is
     * then read again and fixed, twice for the first one, which is reported. The refused saves close the entity
     * manager; the next saves work on a fresh one.
     */
    public function testConcurrentChangeIsRetriedOnceThenReportedWithoutStoppingTheRun(): void
    {
        $ids = [
            $this->catalogProduct(TaxClassCode::PrintedBook),
            $this->catalogProduct(TaxClassCode::PrintedBook),
            $this->catalogProduct(TaxClassCode::PrintedBook),
        ];
        sort($ids);
        foreach ($ids as $id) {
            ProductPriceRecordFactory::store($this->entityManager, ['productId' => $id, 'gross' => 5490, 'taxClass' => 'standard', 'vatRateBp' => 2300]);
        }
        $this->entityManager->clear();
        $tester = $this->tester(new DoctrineProductPriceRepository($this->entityManager), [$ids[0] => 2, $ids[1] => 1]);

        self::assertSame(1, $tester->execute(['--apply' => true]), $tester->getDisplay());

        $display = $this->display($tester);
        self::assertStringContainsString($ids[0].': standard 2300 bp -> printed_book 500 bp: not fixed, the price was changed concurrently', $display);
        self::assertStringContainsString($ids[1].': standard 2300 bp -> printed_book 500 bp: fixed', $display);
        self::assertStringContainsString($ids[2].': standard 2300 bp -> printed_book 500 bp: fixed', $display);
        self::assertStringContainsString('Checked 3 prices: 2 fixed, 1 not fixed.', $display);
        // Two staff changes, no reconciliation.
        self::assertSame($this->stored(5690, 2300, 'standard', 3), $this->price($ids[0]));
        // The staff change, then the reconciliation of its gross.
        self::assertSame($this->stored(5590, 500, 'printed_book', 3), $this->price($ids[1]));
        self::assertSame($this->stored(5490, 500, 'printed_book', 2), $this->price($ids[2]));
        self::assertSame(['staff', 'tax_reconciliation'], $this->connection->fetchFirstColumn('SELECT source FROM pricing_price_history WHERE product_id = ? ORDER BY changed_at, id', [$ids[1]]));

        $this->entityManager->clear();
        $again = $this->tester(new DoctrineProductPriceRepository($this->entityManager), []);
        self::assertSame(0, $again->execute(['--apply' => true]), $again->getDisplay());
        self::assertSame($this->stored(5690, 500, 'printed_book', 4), $this->price($ids[0]));
    }

    public function testUnavailableDatabaseStopsTheRunWithTwo(): void
    {
        $product = $this->catalogProduct(TaxClassCode::PrintedBook);
        ProductPriceRecordFactory::store($this->entityManager, ['productId' => $product, 'gross' => 5490, 'taxClass' => 'standard', 'vatRateBp' => 2300]);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $this->connection->getParams();
        // Nothing listens there: every statement fails to connect, as when PostgreSQL is down. A plain DBAL
        // configuration, so the DAMA static connection is not used.
        $unreachable = DriverManager::getConnection(['host' => '127.0.0.1', 'port' => 1] + $params, new DbalConfiguration());
        $tester = $this->tester(new DoctrineProductPriceRepository(new EntityManager($unreachable, $this->entityManager->getConfiguration())), []);

        self::assertSame(2, $tester->execute(['--apply' => true]), $tester->getDisplay());

        $display = $this->display($tester);
        self::assertStringContainsString('The reconciliation stopped:', $display);
        self::assertStringContainsString('Connection refused', $display);
        self::assertSame($this->stored(5490, 2300, 'standard', 1), $this->price($product));
    }

    private function containerCommand(): CommandTester
    {
        return new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('pricing:reconcile-tax'));
    }

    /**
     * The command on the given repository, with competing staff changes played before a save of the prices given.
     *
     * @param array<string, int> $competingChanges product id => number of saves preceded by a staff change
     */
    private function tester(ProductPriceRepository $prices, array $competingChanges): CommandTester
    {
        $commandBus = self::getContainer()->get('command.bus');
        $queryBus = self::getContainer()->get('query.bus');
        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(MessageBusInterface::class, $commandBus);
        self::assertInstanceOf(MessageBusInterface::class, $queryBus);
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $connection = $this->connection;
        $competing = new class($prices, $competingChanges, static function (string $id) use ($commandBus, $connection): void {
            $row = $connection->fetchAssociative('SELECT gross_amount, version FROM pricing_product_price WHERE product_id = ?', [$id]);
            \assert(\is_array($row) && \is_int($row['gross_amount']) && \is_int($row['version']));
            $commandBus->dispatch(SetProductPrice::byStaff($id, $row['gross_amount'] + 100, $row['version'], 'sub-2', 'Jan Admin'));
        }) implements ProductPriceRepository {
            /**
             * @param array<string, int>     $changes
             * @param \Closure(string): void $change
             */
            public function __construct(private readonly ProductPriceRepository $inner, private array $changes, private readonly \Closure $change)
            {
            }

            public function find(ProductId $id): ?ProductPrice
            {
                return $this->inner->find($id);
            }

            public function findMany(array $ids): array
            {
                return $this->inner->findMany($ids);
            }

            public function page(?ProductId $after, int $limit): array
            {
                return $this->inner->page($after, $limit);
            }

            public function save(ProductPrice $price): void
            {
                $id = $price->id()->toString();
                if (($this->changes[$id] ?? 0) > 0) {
                    --$this->changes[$id];
                    ($this->change)($id);
                }
                $this->inner->save($price);
            }
        };

        $application = new ConsoleApplication();
        $application->addCommand(new ReconcileTaxCommand(new ReconcileTax(
            $competing,
            new QueryBusCatalogProducts($queryBus),
            new PolishVatRates(),
            new DoctrinePersistenceSession($registry),
        )));

        return new CommandTester($application->find('pricing:reconcile-tax'));
    }

    /**
     * @return array{gross_amount: int, net_amount: int, vat_amount: int, vat_rate_bp: int, tax_class: string, version: int}
     */
    private function stored(int $gross, int $basisPoints, string $taxClass, int $version): array
    {
        $breakdown = PriceBreakdown::fromGross(GrossPrice::pln($gross), VatRate::fromBasisPoints($basisPoints));

        return [
            'gross_amount' => $gross,
            'net_amount' => $breakdown->net()->amount(),
            'vat_amount' => $breakdown->vat()->amount(),
            'vat_rate_bp' => $basisPoints,
            'tax_class' => $taxClass,
            'version' => $version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function price(string $productId): array
    {
        $row = $this->connection->fetchAssociative('SELECT gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version FROM pricing_product_price WHERE product_id = ?', [$productId]);
        self::assertIsArray($row);

        return $row;
    }

    private function display(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }

    private function catalogProduct(TaxClassCode $taxClass): string
    {
        $category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($this->entityManager)->save($category);
        $product = ProductFactory::createOne(['mainCategory' => $category, 'taxClass' => $taxClass]);
        new DoctrineProductRepository($this->entityManager)->save($product);

        return $product->id()->toString();
    }
}
