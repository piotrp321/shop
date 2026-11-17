<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Application\Query\GetCatalogStatistics;
use App\Catalog\Application\Query\GetCatalogStatisticsHandler;
use App\Catalog\Infrastructure\Persistence\Doctrine\DbalBackofficeProductSource;
use App\Catalog\Infrastructure\Persistence\Doctrine\PostgresProductTableStatistics;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use App\Tests\Support\InMemory\FixedCountProductIndexer;
use App\Tests\Support\InMemory\InMemoryCategoryRepository;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The number of products from the statistics of the database: none before the first analysis (reltuples is negative),
 * the estimate after ANALYZE, and never a query of the products themselves.
 *
 * The products are in a temporary table that takes the place of catalog_product for the connection of the test, as in
 * the plan tests: the statistics of the real table are not touched.
 */
final class PostgresProductTableStatisticsTest extends KernelTestCase
{
    private Connection $connection;
    private RecordingConnection $recording;
    private PostgresProductTableStatistics $statistics;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->connection->executeStatement('CREATE TEMP TABLE catalog_product (LIKE public.catalog_product INCLUDING ALL)');
        $this->recording = RecordingConnection::like($this->connection);
        $this->statistics = new PostgresProductTableStatistics($this->recording);
    }

    public function testATableNeverAnalysedHasNoEstimate(): void
    {
        $this->insertProducts(50);
        self::assertSame(-1, $this->connection->fetchOne("SELECT reltuples::bigint FROM pg_class WHERE oid = to_regclass('catalog_product')"));

        self::assertNull($this->statistics->estimatedProductCount());
    }

    public function testAfterTheRefreshTheEstimateIsTheNumberOfProducts(): void
    {
        $this->insertProducts(1200);

        $this->statistics->refresh();

        self::assertSame(1200, $this->statistics->estimatedProductCount());
    }

    public function testAnAnalysedEmptyTableHasAnEstimateOfZero(): void
    {
        $this->statistics->refresh();

        self::assertSame(0, $this->statistics->estimatedProductCount());
    }

    public function testTheEstimateReadsOnlyTheCatalogueOfTheDatabaseNeverTheProducts(): void
    {
        $this->insertProducts(10);
        $this->statistics->refresh();

        $this->statistics->estimatedProductCount();

        self::assertCount(1, $this->recording->queries);
        self::assertStringContainsString('pg_class', $this->recording->queries[0][0]);
        self::assertDoesNotMatchRegularExpression('/count\s*\(|FROM\s+catalog_product/i', $this->recording->queries[0][0]);
    }

    public function testTheStatisticsOfTheDashboardNeverCountProductsAlsoWhenTheTableWasNeverAnalysed(): void
    {
        $this->insertProducts(50);
        $handler = new GetCatalogStatisticsHandler(
            new DbalBackofficeProductSource($this->recording, new BoundedCounter($this->recording)),
            new InMemoryCategoryRepository(),
            new FixedCountProductIndexer([50]),
            $this->statistics,
        );

        $view = $handler(new GetCatalogStatistics(false));

        self::assertNull($view->productCount);
        self::assertNull($view->productsWithoutImages);
        $sql = array_map(static fn (array $query): string => $query[0], $this->recording->queries);
        self::assertCount(1, $sql, implode("\n", $sql));
        self::assertStringContainsString('pg_class', $sql[0]);
    }

    private function insertProducts(int $count): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, primary_category_id, version, variant_axes, status, updated_at)
                SELECT gen_random_uuid(), 'produkt-'||n, 'Produkt '||n, '', 'standard', '[]'::jsonb, gen_random_uuid(), 1, '[]'::jsonb, 'published', now()
                FROM generate_series(1, :count) AS n
                SQL,
            ['count' => $count],
        );
    }
}
