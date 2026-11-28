<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Application\Query\ProductSort;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Persistence\Doctrine\DbalBackofficeProductSource;
use App\Shared\Infrastructure\Persistence\BoundedCounter;
use App\Tests\Support\RecordingConnection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The products without images on 100 000 products, with the plan as the planner chooses it on its own statistics (after
 * ANALYZE), not steered by switches: the page and the count read the partial index of the products without images and
 * the table is not scanned whole.
 *
 * The products are in a temporary table that takes the place of catalog_product for the connection of the test (a copy of
 * its definition with its indexes), so the table of the test database is not filled with rows that the rollback leaves
 * behind as garbage.
 */
final class ProductsWithoutImagesPlanTest extends KernelTestCase
{
    private const int PRODUCTS = 100_000;
    /** Every 1 000th product has no image: 100 of them. */
    private const int WITHOUT_EVERY = 1_000;

    private Connection $connection;
    private RecordingConnection $recording;
    private DbalBackofficeProductSource $source;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->connection = $entityManager->getConnection();
        $this->connection->executeStatement('CREATE TEMP TABLE catalog_product (LIKE public.catalog_product INCLUDING ALL)');
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, primary_category_id, version, variant_axes, status, updated_at)
                SELECT gen_random_uuid(), 'produkt-'||n, 'Produkt '||lpad(n::text, 6, '0'), '', 'standard',
                       CASE WHEN n % :every = 0 THEN '[]'::jsonb ELSE '[{"key": "originals/a.jpg", "alt": "a"}]'::jsonb END,
                       gen_random_uuid(), 1, '[]'::jsonb, CASE WHEN n % 7 = 0 THEN 'draft' ELSE 'published' END,
                       timestamptz '2026-01-01 00:00:00+00' + n * interval '1 minute'
                FROM generate_series(1, :products) AS n
                SQL,
            ['every' => self::WITHOUT_EVERY, 'products' => self::PRODUCTS],
        );
        $this->connection->executeStatement('ANALYZE catalog_product');
        $this->recording = RecordingConnection::like($this->connection);
        $this->source = new DbalBackofficeProductSource($this->recording, new BoundedCounter($this->recording));
    }

    public function testThePagesAndTheCountOfTheProductsWithoutImagesAreReadThroughTheirPartialIndexNotByAScanOfTheTable(): void
    {
        $index = $this->partialIndex();
        self::assertSame(self::PRODUCTS, $this->connection->fetchOne('SELECT count(*) FROM catalog_product'));
        $filters = [
            'by name' => [null, null, ProductSort::Name],
            'by name, descending' => [null, null, ProductSort::NameDescending],
            'by the last change' => [null, null, ProductSort::UpdatedAtDescending],
            'with a status' => [null, ProductStatus::Published, ProductSort::Name],
            'with a term' => ['Produkt 0010', null, ProductSort::Name],
        ];

        foreach ($filters as $label => [$term, $status, $sort]) {
            $page = $this->source->page($term, null, $status, null, $sort, 26, true);
            $pagePlan = $this->recording->planOfTheLastQuery($this->connection, false);
            $count = $this->source->count($term, null, $status, true);
            $countPlan = $this->recording->planOfTheLastQuery($this->connection, false);

            self::assertNotSame([], $page, $label);
            foreach ($page as $product) {
                self::assertSame(0, $this->connection->fetchOne('SELECT jsonb_array_length(images) FROM catalog_product WHERE id = ?', [$product->id]), $label);
            }
            self::assertFalse($count->capped, $label);
            foreach (['page' => $pagePlan, 'count' => $countPlan] as $kind => $plan) {
                self::assertMatchesRegularExpression($index, $plan, $label.' '.$kind."\n".$plan);
                self::assertDoesNotMatchRegularExpression('/Seq Scan on catalog_product(?![_a-z])/', $plan, $label.' '.$kind."\n".$plan);
            }
        }
        self::assertSame(100, $this->source->count(null, null, null, true)->value);
        self::assertSame(86, $this->source->count(null, null, ProductStatus::Published, true)->value);
    }

    public function testTheQueueOfTheDashboardCountsThroughThePartialIndexUpToItsLimit(): void
    {
        $index = $this->partialIndex();

        $capped = $this->source->count(null, null, null, true, 50);
        $plan = $this->recording->planOfTheLastQuery($this->connection, false);
        $all = $this->source->count(null, null, null, true, 1000);

        self::assertSame([50, true], [$capped->value, $capped->capped]);
        self::assertSame([100, false], [$all->value, $all->capped]);
        self::assertMatchesRegularExpression($index, $plan, $plan);
        self::assertDoesNotMatchRegularExpression('/Seq Scan on catalog_product(?![_a-z])/', $plan, $plan);
    }

    /**
     * A pattern of the names of the partial indexes of the temporary copy that hold the products without images: the one by id and the
     * ones by category and sort key (PERF-7-3), any of which reads only those products.
     */
    private function partialIndex(): string
    {
        $names = $this->connection->fetchFirstColumn("SELECT indexname FROM pg_indexes WHERE tablename = 'catalog_product' AND schemaname LIKE 'pg_temp%' AND indexdef LIKE '%WHERE (images%'");
        self::assertNotSame([], $names);

        return '/\b('.implode('|', array_map(static fn (mixed $name): string => preg_quote(\is_string($name) ? $name : '', '/'), $names)).')\b/';
    }
}
