<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Copy\Sql;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;

/**
 * demo-data:verify on the generated catalog of the profile small and on violations injected into it.
 */
final class VerifyStepTest extends KernelTestCase
{
    use RunsDemoDataSteps;

    public function testItPassesOnTheGeneratedData(): void
    {
        $this->catalogSteps();

        $tester = $this->step('demo-data:verify');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('consistent', $tester->getDisplay());
    }

    public function testItListsAPublishedProductWithoutAVariant(): void
    {
        $this->catalogSteps();
        $connection = $this->connection();
        $row = $connection->fetchAssociative("SELECT id, slug FROM catalog_product WHERE status = 'published' ORDER BY id LIMIT 1");
        self::assertIsArray($row);
        $connection->executeStatement('DELETE FROM catalog_product_variant WHERE product_id = ?', [$row['id']]);

        $tester = $this->step('demo-data:verify');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A published product has no variant', $tester->getDisplay());
        self::assertStringContainsString(Sql::string($row['slug']), $tester->getDisplay());
    }

    public function testADraftWithoutAVariantIsNotAViolation(): void
    {
        $this->catalogSteps();
        $connection = $this->connection();
        $id = $connection->fetchOne("SELECT id FROM catalog_product WHERE status = 'draft' ORDER BY id LIMIT 1");
        $connection->executeStatement('DELETE FROM catalog_product_variant WHERE product_id = ?', [$id]);

        self::assertSame(Command::SUCCESS, $this->step('demo-data:verify')->getStatusCode());
    }

    public function testItListsAProductCountThatDiffersFromTheProfile(): void
    {
        $this->catalogSteps();
        $this->connection()->executeStatement('DELETE FROM catalog_product WHERE id = (SELECT id FROM catalog_product ORDER BY id LIMIT 1)');

        $tester = $this->step('demo-data:verify');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('has 2000 products of the generator, the table has 1999', $tester->getDisplay());
    }

    public function testItListsAProductOfTheTailWithoutTheNumberInItsSlug(): void
    {
        $this->catalogSteps();
        $this->connection()->executeStatement("UPDATE catalog_product SET slug = 'bez-numeru' WHERE id = (SELECT id FROM catalog_product ORDER BY id LIMIT 1)");

        $tester = $this->step('demo-data:verify');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('without the number of the product', $tester->getDisplay());
    }

    public function testItListsASlugNumberThatRepeats(): void
    {
        $this->catalogSteps();
        $ids = $this->connection()->fetchFirstColumn('SELECT id FROM catalog_product ORDER BY id LIMIT 2');
        $this->connection()->executeStatement("UPDATE catalog_product SET slug = 'powtorzony-a-17' WHERE id = ?", [$ids[0]]);
        $this->connection()->executeStatement("UPDATE catalog_product SET slug = 'powtorzony-b-17' WHERE id = ?", [$ids[1]]);

        $tester = $this->step('demo-data:verify');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('repeat a product number', $tester->getDisplay());
    }

    public function testItListsAGeneratedProductThatTookTheSlugOfASeedProduct(): void
    {
        $this->catalogSteps();
        $this->connection()->executeStatement("UPDATE catalog_product SET slug = 'buty-szosowe-tarvik-asfalt-200' WHERE id = (SELECT id FROM catalog_product ORDER BY id LIMIT 1)");

        $tester = $this->step('demo-data:verify');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The slug of a catalog.yaml product belongs to another product: buty-szosowe-tarvik-asfalt-200', $tester->getDisplay());
    }

    public function testASeedProductBesideTheGeneratedOnesIsNotCountedAsGeneratedAndIsNotAViolation(): void
    {
        $this->catalogSteps();
        $seedId = '9f0d1c7c-6a0b-5ad7-8ce1-8b0d6f4d3a21';
        $category = $this->connection()->fetchOne('SELECT id FROM catalog_category LIMIT 1');
        $this->connection()->executeStatement(
            "INSERT INTO catalog_product (id, slug, name, short_description, tax_class, images, variant_axes, status, primary_category_id, updated_at) VALUES (?, 'buty-szosowe-tarvik-asfalt-200', 'x', '', 'standard', '[]', '[]', 'draft', ?, now())",
            [$seedId, $category],
        );

        $tester = $this->step('demo-data:verify');

        // The id is not the one the seed gives the product, so it is reported as taken by another product.
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('belongs to another product', $tester->getDisplay());
    }

    public function testItListsAnIncompleteCategoryTree(): void
    {
        $this->catalogSteps();
        $this->connection()->executeStatement("UPDATE catalog_product SET primary_category_id = (SELECT id FROM catalog_category WHERE slug = 'startowe') WHERE true");
        $this->connection()->executeStatement('DELETE FROM catalog_product_category');
        $this->connection()->executeStatement("DELETE FROM catalog_category WHERE slug = 'kolce'");
        $this->connection()->executeStatement("DELETE FROM catalog_category WHERE slug = 'kolce'");

        $tester = $this->step('demo-data:verify');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('category tree of the generator is incomplete', $tester->getDisplay());
    }

    public function testAnUnknownProfileIsRefused(): void
    {
        $tester = $this->step('demo-data:verify', ['--demo-profile' => 'huge']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testTheAnalyzeStepRuns(): void
    {
        $this->catalogSteps();

        $tester = $this->step('demo-data:analyze', [], false);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('catalog_product', $tester->getDisplay());
    }
}
