<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\Catalog\Application\Command\CreateCategory;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductBrand;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductDescription;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductParameters;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;
use App\Catalog\Infrastructure\Image\FlysystemProductImageStorage;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\DemoData\Catalog\CategoryTree;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Copy\Sql;
use App\DemoData\Photo\ImageKeys;
use App\DemoData\Profile\Profiles;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The catalog steps of the generator with the profile small, on shop_test in the transaction of the test.
 */
final class CatalogStepsTest extends KernelTestCase
{
    use RunsDemoDataSteps;

    private static function profiles(): Profiles
    {
        return Profiles::fromFile(\dirname(__DIR__, 3).'/fixtures/demo-data/profiles.yaml');
    }

    protected function setUp(): void
    {
        self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM catalog_product')), 'shop_test has catalog rows that are not the ones of this test.');
        self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM catalog_category')), 'shop_test has catalog rows that are not the ones of this test.');
    }

    public function testTheCatalogStepsOfTheSmallProfileEndWithCodeZeroAndFillTheTables(): void
    {
        $this->catalogSteps();

        $connection = $this->connection();
        self::assertSame(2_000, $this->int($connection->fetchOne('SELECT count(*) FROM catalog_product')));
        self::assertSame(
            \count(CategoryTree::build(new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data'), self::profiles()->namespace)),
            $this->int($connection->fetchOne('SELECT count(*) FROM catalog_category')),
        );
        $average = Sql::float($connection->fetchOne('SELECT count(*)::float / (SELECT count(*) FROM catalog_product) FROM catalog_product_variant'));
        self::assertEqualsWithDelta(3.0, $average, 0.35);
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM catalog_product p WHERE NOT EXISTS (SELECT 1 FROM catalog_product_variant v WHERE v.product_id = p.id)')));
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM catalog_product_variant WHERE values_key IS NULL')));
        self::assertGreaterThan(200, $this->int($connection->fetchOne('SELECT count(*) FROM catalog_product_category')));
    }

    public function testTheDistributionsOfTheSmallProfileAreWithinTheTolerances(): void
    {
        $this->catalogSteps();
        $connection = $this->connection();

        $single = $this->int($connection->fetchOne('SELECT count(*) FROM (SELECT product_id FROM catalog_product_variant GROUP BY product_id HAVING count(*) = 1) s'));
        self::assertEqualsWithDelta(0.40, $single / 2_000, 0.05);
        self::assertEqualsWithDelta(40, $this->int($connection->fetchOne("SELECT count(*) FROM catalog_product WHERE status = 'draft'")), 22);
        self::assertSame(0, $this->int($connection->fetchOne("SELECT count(*) FROM catalog_product WHERE status = 'draft' AND first_published_at IS NOT NULL")));
        self::assertSame(0, $this->int($connection->fetchOne("SELECT count(*) FROM catalog_product WHERE status = 'published' AND first_published_at IS NULL")));

        // Every 50th product (by the number in the slug) has no photos, the others have one to three.
        $rows = $connection->fetchAllAssociative("SELECT substring(slug from '-([0-9]+)\$')::int AS n, jsonb_array_length(images) AS images FROM catalog_product");
        self::assertCount(2_000, $rows);
        foreach ($rows as $row) {
            $number = Sql::int($row['n']);
            $images = Sql::int($row['images']);
            if (0 === $number % 50) {
                self::assertSame(0, $images, 'product '.$number);
            } else {
                self::assertGreaterThanOrEqual(1, $images, 'product '.$number);
                self::assertLessThanOrEqual(3, $images, 'product '.$number);
            }
        }
        self::assertSame(40, $this->int($connection->fetchOne("SELECT count(*) FROM catalog_product WHERE images = '[]'::jsonb")));
    }

    public function testThePhotosAreStoredOnceInGarageUnderTheKeysTheProductsPointAt(): void
    {
        $this->catalogSteps();

        $storage = self::getContainer()->get('test.product_images.storage');
        self::assertInstanceOf(FilesystemOperator::class, $storage);
        $keys = $this->connection()->fetchFirstColumn("SELECT DISTINCT img->>'key' FROM catalog_product, jsonb_array_elements(images) img");
        self::assertNotSame([], $keys);
        self::assertLessThanOrEqual(37, \count($keys));
        foreach ($keys as $key) {
            self::assertIsString($key);
            ImageKey::fromString($key);
            self::assertTrue($storage->fileExists($key), $key);
        }
    }

    public function testTwoRunsWithTheSameAnchorGiveTheSameRowsInTheCatalogTables(): void
    {
        $this->catalogSteps();
        $first = $this->checksums($this->connection());
        self::assertNotContains('empty', $first);

        $this->emptyCatalog();
        $this->catalogSteps();

        self::assertSame($first, $this->checksums($this->connection()));
    }

    public function testAnotherAnchorMovesTheTimesAndNothingElse(): void
    {
        $this->catalogSteps();
        $first = $this->text($this->connection()->fetchOne("SELECT md5(string_agg(id::text || slug || name, '|' ORDER BY id)) FROM catalog_product"));
        $firstTime = $this->text($this->connection()->fetchOne('SELECT min(first_published_at) FROM catalog_product'));

        $this->emptyCatalog();
        $this->succeeds('demo-data:categories', ['--anchor' => '2026-12-17T00:00:00Z']);
        $this->succeeds('demo-data:products', ['--anchor' => '2026-12-17T00:00:00Z']);

        self::assertSame($first, $this->text($this->connection()->fetchOne("SELECT md5(string_agg(id::text || slug || name, '|' ORDER BY id)) FROM catalog_product")));
        self::assertNotSame($firstTime, $this->text($this->connection()->fetchOne('SELECT min(first_published_at) FROM catalog_product')));
    }

    public function testTheProductsStepSplitIntoWorkersGivesTheSameRowsAsOneProcess(): void
    {
        $this->succeeds('demo-data:categories');
        $this->succeeds('demo-data:products');
        $whole = $this->checksums($this->connection());

        $this->emptyCatalog();
        $this->succeeds('demo-data:categories');
        foreach ([2, 0, 1] as $worker) {
            $this->succeeds('demo-data:products', ['--worker' => (string) $worker, '--workers' => '3']);
        }

        self::assertSame($whole, $this->checksums($this->connection()));
    }

    public function testAnExistingDepartmentOfTheSeedCatalogIsReusedAndNotWrittenAgain(): void
    {
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $seedId = '3f6c9a52-7d1e-4b8a-9c40-5e2a1d7b6f83';
        $bus->dispatch(CreateCategory::bySeed($seedId, 'Bieganie', 'bieganie', null));

        $this->catalogSteps();

        self::assertSame(1, $this->int($this->connection()->fetchOne("SELECT count(*) FROM catalog_category WHERE parent_id IS NULL AND slug = 'bieganie'")));
        $groupParent = $this->connection()->fetchOne("SELECT parent_id FROM catalog_category WHERE slug = 'obuwie-startowe'");
        self::assertSame($seedId, $groupParent);
        $path = $this->text($this->connection()->fetchOne("SELECT path FROM catalog_category WHERE slug = 'startowe'"));
        self::assertStringStartsWith('/'.$seedId.'/', $path);
    }

    public function testRunningTheCategoriesStepAgainChangesNothing(): void
    {
        $this->succeeds('demo-data:categories');
        $before = $this->checksums($this->connection());

        $this->succeeds('demo-data:categories');

        self::assertSame($before, $this->checksums($this->connection()));
    }

    public function testTheProductsStepFailsWithoutTheCategoriesAndWritesNothing(): void
    {
        $tester = $this->step('demo-data:products');

        self::assertNotSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testAProfileOtherThanSmallNeedsTheVariablesOfThePhotoPoolAndChangesNothingWithoutThem(): void
    {
        $this->succeeds('demo-data:categories');
        $this->succeeds('demo-data:products');
        $before = $this->checksums($this->connection());

        $tester = $this->step('demo-data:photos', ['--demo-profile' => 'medium']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('DEMO_PHOTOS_ENDPOINT', $tester->getDisplay());
        self::assertSame($before, $this->checksums($this->connection()));
    }

    public function testAnUnknownProfileIsRefusedWithTheKnownNames(): void
    {
        $tester = $this->step('demo-data:categories', ['--demo-profile' => 'huge']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('small, medium, server, full', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function badOptions(): iterable
    {
        yield 'a seed that is not a UUID' => [['--seed' => 'not-a-uuid'], 'not a UUID'];
        yield 'an anchor that is not a date' => [['--anchor' => 'sometime soon'], 'not a date'];
        yield 'zero products' => [['--products' => '0'], 'at least 1'];
        yield 'a negative number of products' => [['--products' => '-5'], 'at least 1'];
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('badOptions')]
    public function testABadOptionIsRefusedWithAMessageAndWritesNothing(array $options, string $message): void
    {
        $tester = $this->step('demo-data:categories', $options);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay());
        self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM catalog_category')));
    }

    public function testTheWorkerOptionsAreChecked(): void
    {
        $this->succeeds('demo-data:categories');

        foreach ([['--worker' => '3', '--workers' => '3'], ['--worker' => '-1', '--workers' => '2'], ['--worker' => '0', '--workers' => '0']] as $options) {
            $tester = $this->step('demo-data:products', $options);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('--worker', $tester->getDisplay());
        }
        self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM catalog_product')));
    }

    public function testListPartsOnlyPrintsTheNumberOfParts(): void
    {
        $tester = $this->step('demo-data:products', ['--list-parts' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('4', trim($tester->getDisplay()));
        self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM catalog_product')));
    }

    public function testAProductsOverrideChangesTheNumberOfProductsOnly(): void
    {
        $this->succeeds('demo-data:categories');
        $this->succeeds('demo-data:products', ['--products' => '120']);

        self::assertSame(120, $this->int($this->connection()->fetchOne('SELECT count(*) FROM catalog_product')));
    }

    public function testTheVariantKeyIsTheOneTheCatalogWritesForTheSameValues(): void
    {
        $this->succeeds('demo-data:categories');
        $this->succeeds('demo-data:products', ['--products' => '40']);
        $generated = $this->connection()->fetchAllAssociative("SELECT v.option_values, v.values_key FROM catalog_product_variant v WHERE v.option_values <> '[]'::jsonb ORDER BY v.id LIMIT 30");
        self::assertNotSame([], $generated);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $category = $this->text($this->connection()->fetchOne('SELECT id FROM catalog_category LIMIT 1'));
        $variants = [];
        $expected = [];
        $seen = [];
        foreach ($generated as $i => $row) {
            $values = json_decode(Sql::string($row['option_values']), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($values);
            // The variants are the ones of different products: one product takes each combination once.
            if (2 !== \count($values) || isset($seen[Sql::string($row['values_key'])])) {
                continue;
            }
            $seen[Sql::string($row['values_key'])] = true;
            /** @var list<string> $values */
            $variants[] = new ProductVariant(VariantId::fromString('00000000-0000-4000-8000-'.str_pad((string) $i, 12, '0', \STR_PAD_LEFT)), VariantValues::fromList($values));
            $expected['00000000-0000-4000-8000-'.str_pad((string) $i, 12, '0', \STR_PAD_LEFT)] = $row['values_key'];
        }
        self::assertGreaterThan(3, \count($variants));

        $product = Product::create(
            ProductId::fromString('00000000-0000-4000-8000-0000000000aa'),
            ProductName::of('Kontrakt'),
            new Slug('kontrakt-values-key'),
            ShortDescription::of(''),
            ProductBrand::of(''),
            ProductDescription::of(''),
            ProductParameters::create([]),
            ProductCategories::create(CategoryId::fromString($category)),
            TaxClassCode::Standard,
            ProductImages::fromList([]),
            ProductVariants::create(VariantAxes::fromList(['Kolor', 'Rozmiar']), $variants),
            ProductStatus::Published,
            new \DateTimeImmutable(),
        );
        new DoctrineProductRepository($entityManager)->save($product, null, null, new \DateTimeImmutable());

        $stored = $this->connection()->fetchAllKeyValue('SELECT id, values_key FROM catalog_product_variant WHERE product_id = ?', ['00000000-0000-4000-8000-0000000000aa']);
        ksort($expected);
        ksort($stored);
        self::assertSame($expected, $stored);
    }

    public function testThePathOfEveryCategoryIsTheOneTheCatalogWritesForTheSameTree(): void
    {
        $dictionaries = new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data');
        $specs = CategoryTree::build($dictionaries, self::profiles()->namespace);
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        foreach (\array_slice($specs, 0, 20) as $spec) {
            $bus->dispatch(CreateCategory::bySeed($spec->id, $spec->name, $spec->slug, $spec->parentId));
        }

        $stored = $this->connection()->fetchAllKeyValue('SELECT id, path FROM catalog_category');
        self::assertCount(20, $stored);
        foreach (\array_slice($specs, 0, 20) as $spec) {
            self::assertSame($spec->path, $stored[$spec->id], $spec->slugPath);
        }
    }

    public function testTheKeyOfAPhotoIsTheOneTheCatalogStorageWritesForTheSameFile(): void
    {
        $storage = self::getContainer()->get(FlysystemProductImageStorage::class);
        self::assertInstanceOf(FlysystemProductImageStorage::class, $storage);

        foreach (['runner-country-road.jpg', 'yoga-park-sunset.jpg', 'soccer-ball-field-blue.jpg'] as $file) {
            $contents = (string) file_get_contents(\dirname(__DIR__, 3).'/fixtures/images/'.$file);

            self::assertSame($storage->store($contents), ImageKeys::forContents($contents), $file);
        }
    }

    public function testTheGeneratorStaysInsideTheRulesOfTheProductGenerator(): void
    {
        // The parts the step writes are the ones of the generator for the same inputs.
        $profiles = self::profiles();
        $generator = new ProductGenerator(new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data'), $profiles->namespace, new \DateTimeImmutable(self::ANCHOR), 2_000, $profiles->partSize);
        $this->succeeds('demo-data:categories');
        $this->succeeds('demo-data:products');

        $spec = iterator_to_array($generator->part(1), false)[7];
        $row = $this->connection()->fetchAssociative('SELECT slug, name, short_description, tax_class, status FROM catalog_product WHERE id = ?', [$spec->id]);
        self::assertIsArray($row);
        self::assertSame([$spec->slug, $spec->name, $spec->shortDescription, $spec->taxClass, $spec->status], array_values($row));
    }
}
