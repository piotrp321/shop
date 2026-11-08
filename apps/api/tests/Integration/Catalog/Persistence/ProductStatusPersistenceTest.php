<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use App\Tests\Support\StatementCounter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The status, the first publication and the metadata of the last change (updated_at, updated_by) of a stored
 * product, and the version the change is based on (expectedVersion).
 */
final class ProductStatusPersistenceTest extends KernelTestCase
{
    use Factories;

    private const string CREATED_AT = '2026-11-07T08:00:00+00:00';
    private const string EDITED_AT = '2026-11-07T09:00:00+00:00';

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineProductRepository $products;
    private Category $category;

    protected function setUp(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->products = new DoctrineProductRepository($entityManager);
        $this->category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($this->category);
    }

    public function testADraftIsStoredWithItsStatusTheTimeAndTheActorAtVersionOne(): void
    {
        $product = $this->newProduct(ProductStatus::Draft);

        $this->products->save($product, null, 'staff-1', new \DateTimeImmutable(self::CREATED_AT));
        $this->entityManager->clear();

        $loaded = $this->products->get($product->id());
        self::assertSame(ProductStatus::Draft, $loaded->status());
        self::assertNull($loaded->firstPublishedAt());
        self::assertSame(1, $loaded->version());
        self::assertSame(
            ['status' => 'draft', 'first_published_at' => null, 'updated_by' => 'staff-1', 'updated_at' => '2026-11-07 08:00:00+00', 'version' => 1],
            $this->row($product),
        );
    }

    public function testAPublishedProductIsReadBackWithItsFirstPublication(): void
    {
        $product = $this->newProduct(ProductStatus::Published);

        $this->products->save($product, null, null, new \DateTimeImmutable(self::CREATED_AT));
        $this->entityManager->clear();

        $loaded = $this->products->get($product->id());
        self::assertSame(ProductStatus::Published, $loaded->status());
        self::assertEquals(new \DateTimeImmutable(self::CREATED_AT), $loaded->firstPublishedAt());
        self::assertTrue($loaded->isSlugLocked());
        self::assertNull($this->row($product)['updated_by']);
    }

    public function testPublishAndUnpublishAreStoredWithANewVersionTimeAndActorAndTheFirstPublicationStays(): void
    {
        $product = $this->newProduct(ProductStatus::Draft, imageAlts: ['front']);
        $this->products->save($product, null, 'staff-1', new \DateTimeImmutable(self::CREATED_AT));
        $this->entityManager->clear();

        $draft = $this->products->get($product->id());
        $draft->publish(new \DateTimeImmutable(self::EDITED_AT));
        $this->products->save($draft, 1, 'staff-2', new \DateTimeImmutable(self::EDITED_AT));
        $this->entityManager->clear();

        self::assertSame(
            ['status' => 'published', 'first_published_at' => '2026-11-07 09:00:00+00', 'updated_by' => 'staff-2', 'updated_at' => '2026-11-07 09:00:00+00', 'version' => 2],
            $this->row($product),
        );

        $published = $this->products->get($product->id());
        $published->unpublish(new \DateTimeImmutable('2026-11-07T10:00:00+00:00'));
        $this->products->save($published, 2, 'staff-3', new \DateTimeImmutable('2026-11-07T10:00:00+00:00'));
        $this->entityManager->clear();

        self::assertSame(
            ['status' => 'draft', 'first_published_at' => '2026-11-07 09:00:00+00', 'updated_by' => 'staff-3', 'updated_at' => '2026-11-07 10:00:00+00', 'version' => 3],
            $this->row($product),
        );
        self::assertSame(3, $this->products->get($product->id())->version());
    }

    public function testAWriteFromAViewRaisesTheVersionAlsoWhenNothingChanged(): void
    {
        $product = $this->newProduct(ProductStatus::Published);
        $this->products->save($product, null, null, new \DateTimeImmutable(self::CREATED_AT));
        $this->entityManager->clear();

        $loaded = $this->products->get($product->id());
        $this->products->save($loaded, 1, 'staff-1', new \DateTimeImmutable(self::EDITED_AT));

        self::assertSame(2, $this->row($product)['version']);
        self::assertSame('staff-1', $this->row($product)['updated_by']);
        self::assertSame('2026-11-07 09:00:00+00', $this->row($product)['updated_at']);
    }

    public function testAWriteFromAViewAtTheSameTimeAndByTheSameActorStillRaisesTheVersion(): void
    {
        $product = $this->newProduct(ProductStatus::Published);
        $this->products->save($product, null, 'staff-1', new \DateTimeImmutable(self::CREATED_AT));
        $this->entityManager->clear();

        $this->products->save($this->products->get($product->id()), 1, 'staff-1', new \DateTimeImmutable(self::CREATED_AT));

        self::assertSame(2, $this->row($product)['version']);
    }

    public function testAChangeOfOnlyTheVariantsRaisesTheVersionAndSetsTheMetadata(): void
    {
        $product = $this->newProduct(ProductStatus::Draft);
        $this->products->save($product, null, null, new \DateTimeImmutable(self::CREATED_AT));
        $this->entityManager->clear();

        $loaded = $this->products->get($product->id());
        $loaded->edit(
            $loaded->name(),
            $loaded->slug(),
            $loaded->shortDescription(),
            $loaded->categories(),
            $loaded->taxClass(),
            $loaded->images(),
            ProductVariants::create($loaded->variantAxes(), [new ProductVariant(VariantId::fromString('0192f0c4-0000-7000-8000-0000000000aa'), VariantValues::fromList([]))]),
            [],
            new \DateTimeImmutable(self::EDITED_AT),
        );
        // A write without a view (as the seed): the version is raised because the variants changed.
        $this->products->save($loaded, null, null, new \DateTimeImmutable(self::EDITED_AT));
        $this->entityManager->clear();

        self::assertSame(2, $this->row($product)['version']);
        self::assertSame('2026-11-07 09:00:00+00', $this->row($product)['updated_at']);
        self::assertSame(['0192f0c4-0000-7000-8000-0000000000aa'], array_map(
            static fn (ProductVariant $variant): string => $variant->id()->toString(),
            $this->products->get($product->id())->variants()->toList(),
        ));
    }

    public function testAnUnchangedWriteWithoutAViewWritesNothingNotEvenTheMetadata(): void
    {
        $product = $this->newProduct(ProductStatus::Published);
        $this->products->save($product, null, 'staff-1', new \DateTimeImmutable(self::CREATED_AT));
        $this->entityManager->clear();
        $counter = self::getContainer()->get(StatementCounter::class);
        self::assertInstanceOf(StatementCounter::class, $counter);
        $writesBefore = $counter->writes();

        $this->products->save($this->products->get($product->id()), null, 'staff-2', new \DateTimeImmutable(self::EDITED_AT));

        self::assertSame(0, $counter->writes() - $writesBefore);
        self::assertSame(['staff-1', '2026-11-07 08:00:00+00', 1], [$this->row($product)['updated_by'], $this->row($product)['updated_at'], $this->row($product)['version']]);
    }

    public function testAStaleExpectedVersionIsRefusedWithoutChangingTheRow(): void
    {
        $product = $this->newProduct(ProductStatus::Draft, imageAlts: ['front']);
        $this->products->save($product, null, 'staff-1', new \DateTimeImmutable(self::CREATED_AT));
        $this->entityManager->clear();
        // Another tab saved in between: the row is at version 2.
        $this->connection->executeStatement('UPDATE catalog_product SET version = 2 WHERE id = ?', [$product->id()->toString()]);
        $this->entityManager->clear();

        $loaded = $this->products->get($product->id());
        $loaded->publish(new \DateTimeImmutable(self::EDITED_AT));
        try {
            $this->products->save($loaded, 1, 'staff-2', new \DateTimeImmutable(self::EDITED_AT));
            self::fail('Expected ProductModifiedConcurrently.');
        } catch (ProductModifiedConcurrently) {
        }

        self::assertSame(
            ['status' => 'draft', 'first_published_at' => null, 'updated_by' => 'staff-1', 'updated_at' => '2026-11-07 08:00:00+00', 'version' => 2],
            $this->row($product),
        );
    }

    /**
     * @param list<string> $imageAlts
     */
    private function newProduct(ProductStatus $status, array $imageAlts = []): Product
    {
        return ProductFactory::createOne([
            'mainCategory' => $this->category,
            'imageAlts' => $imageAlts,
            'status' => $status,
            'createdAt' => new \DateTimeImmutable(self::CREATED_AT),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Product $product): array
    {
        // UTC, so the text of the timestamps does not depend on the session time zone.
        $this->connection->executeStatement("SET TIME ZONE 'UTC'");
        $row = $this->connection->fetchAssociative(
            'SELECT status, first_published_at, updated_by, updated_at, version FROM catalog_product WHERE id = ?',
            [$product->id()->toString()],
        );
        self::assertIsArray($row);

        return $row;
    }
}
