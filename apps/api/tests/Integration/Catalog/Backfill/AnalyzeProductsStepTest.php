<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Backfill;

use App\Catalog\Infrastructure\Backfill\AnalyzeProductsStep;
use App\Catalog\Infrastructure\Persistence\Doctrine\PostgresProductTableStatistics;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The backfill step that gives the product table its statistics, so that the dashboard has the estimate of the number
 * of products after the first rollout. On a temporary table that takes the place of catalog_product.
 */
final class AnalyzeProductsStepTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->connection->executeStatement('CREATE TEMP TABLE catalog_product (LIKE public.catalog_product INCLUDING ALL)');
        $this->connection->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, primary_category_id, version, variant_axes, status, updated_at)
             SELECT gen_random_uuid(), 'p-'||n, 'P '||n, '', 'standard', '[]'::jsonb, gen_random_uuid(), 1, '[]'::jsonb, 'published', now() FROM generate_series(1, 30) n",
        );
    }

    public function testTheStepAnalysesTheProductTableAndReportsIt(): void
    {
        $step = new AnalyzeProductsStep(new PostgresProductTableStatistics($this->connection));
        self::assertSame(-1, $this->estimate());
        $lines = [];

        $step->run(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertSame(30, $this->estimate());
        self::assertSame(['analysed catalog_product'], $lines);
        self::assertSame('catalog:analyze-products', $step->name());
    }

    private function estimate(): mixed
    {
        return $this->connection->fetchOne("SELECT reltuples::bigint FROM pg_class WHERE oid = to_regclass('catalog_product')");
    }
}
