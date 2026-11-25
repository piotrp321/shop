<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Product\Ean;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\Sku;
use App\Catalog\Domain\Product\SkuAlreadyTaken;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineCategoryRepository;
use App\Catalog\Infrastructure\Persistence\Doctrine\DoctrineProductRepository;
use App\Tests\Factory\CategoryFactory;
use App\Tests\Factory\ProductFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The SKU and the EAN of a variant in the table (PRODUCT-DATA, "Model i persystencja", "Unikalność SKU przy równoległym
 * zapisie"): both are stored with the variant and written with every row that is written, also when nothing but a code
 * changed; the unique index of the SKU is the guarantee that no two variants have one, and it lets any number of
 * variants have none.
 */
final class ProductVariantCodesPersistenceTest extends KernelTestCase
{
    use Factories;

    private const string BLACK_M = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const string BLACK_L = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const string WHITE_M = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private EntityManagerInterface $entityManager;
    private Connection $connection;
    private DoctrineProductRepository $products;
    private Category $category;

    protected function setUp(): void
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $entityManager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();
        $this->products = new DoctrineProductRepository($entityManager);
        $this->category = CategoryFactory::createOne();
        new DoctrineCategoryRepository($entityManager)->save($this->category);
    }

    public function testTheCodesOfEveryVariantAreStoredAndReadBackAndAVariantWithoutThemHasNull(): void
    {
        $product = $this->product(['Kolor'], [
            ['id' => self::BLACK_M, 'values' => ['czarne'], 'sku' => 'but-1', 'ean' => '5901234123457'],
            ['id' => self::BLACK_L, 'values' => ['białe'], 'sku' => '', 'ean' => ''],
        ]);

        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        self::assertSame(
            [[self::BLACK_M, 'BUT-1', '5901234123457'], [self::BLACK_L, null, null]],
            array_map(static fn (array $row): array => [$row['id'], $row['sku'], $row['ean']], $this->rows($product)),
        );
        self::assertSame([['BUT-1', '5901234123457'], [null, null]], $this->codes($this->products->get($product->id())));
    }

    public function testAnyNumberOfVariantsWithoutASkuAndAnyNumberWithTheSameEanAreStored(): void
    {
        $first = $this->product(['Kolor'], [
            ['id' => self::BLACK_M, 'values' => ['czarne'], 'ean' => '5901234123457'],
            ['id' => self::BLACK_L, 'values' => ['białe'], 'ean' => '5901234123457'],
        ]);
        $second = $this->product([], [['id' => self::WHITE_M, 'values' => [], 'ean' => '5901234123457']]);

        $this->products->save($first, null, null, new \DateTimeImmutable());
        $this->products->save($second, null, null, new \DateTimeImmutable());

        self::assertSame(3, $this->connection->fetchOne("SELECT count(*) FROM catalog_product_variant WHERE sku IS NULL AND ean = '5901234123457'"));
    }

    public function testASaveThatChangesOnlyTheCodeOfOneVariantWritesItAndTouchesNoOtherCode(): void
    {
        $product = $this->product(['Kolor'], [
            ['id' => self::BLACK_M, 'values' => ['czarne'], 'sku' => 'BUT-1', 'ean' => '5901234123457'],
            ['id' => self::BLACK_L, 'values' => ['białe'], 'sku' => 'BUT-2', 'ean' => '59012344'],
        ]);
        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        $stored = $this->products->get($product->id());
        $stored->edit(
            $stored->name(),
            $stored->slug(),
            $stored->shortDescription(),
            $stored->brand(),
            $stored->description(),
            $stored->parameters(),
            $stored->categories(),
            $stored->taxClass(),
            $stored->images(),
            $this->variants(['Kolor'], [
                ['id' => self::BLACK_M, 'values' => ['czarne'], 'sku' => 'BUT-1B', 'ean' => '5901234123457'],
                ['id' => self::BLACK_L, 'values' => ['białe'], 'sku' => 'BUT-2', 'ean' => '59012344'],
            ]),
            [],
            new \DateTimeImmutable(),
        );
        $this->products->save($stored, $stored->version(), 'staff-1', new \DateTimeImmutable());
        $this->entityManager->clear();

        self::assertSame([['BUT-1B', '5901234123457'], ['BUT-2', '59012344']], $this->codes($this->products->get($product->id())));
        self::assertSame(2, $this->products->get($product->id())->version());
    }

    public function testASaveThatChangesOnlyTheEanOfOneVariantWritesIt(): void
    {
        $product = $this->product([], [['id' => self::BLACK_M, 'values' => [], 'sku' => 'BUT-1', 'ean' => '5901234123457']]);
        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        $this->saveVariants($product->id()->toString(), [], [['id' => self::BLACK_M, 'values' => [], 'sku' => 'BUT-1', 'ean' => '59012344']]);

        self::assertSame([['BUT-1', '59012344']], $this->codes($this->products->get($product->id())));
    }

    public function testTheCodesOfTheVariantsThatStayAreKeptWhenTheRowsAreReplacedAfterAChangeOfAxesAndValues(): void
    {
        $product = $this->product(['Kolor', 'Rozmiar'], [
            ['id' => self::BLACK_M, 'values' => ['czarne', 'M'], 'sku' => 'BUT-1', 'ean' => '5901234123457'],
            ['id' => self::BLACK_L, 'values' => ['czarne', 'L'], 'sku' => 'BUT-2'],
            ['id' => self::WHITE_M, 'values' => ['białe', 'M'], 'ean' => '59012344'],
        ], ['status' => ProductStatus::Draft]);
        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        // The first axis goes, a value changes, the rows are in another order, one row is new.
        $this->saveVariants($product->id()->toString(), ['Rozmiar'], [
            ['id' => self::WHITE_M, 'values' => ['M2'], 'sku' => '', 'ean' => '59012344'],
            ['id' => self::BLACK_M, 'values' => ['M'], 'sku' => 'BUT-1', 'ean' => '5901234123457'],
            ['id' => self::BLACK_L, 'values' => ['L'], 'sku' => 'BUT-2', 'ean' => ''],
        ]);
        $this->entityManager->clear();

        self::assertSame(
            [[self::WHITE_M, null, '59012344'], [self::BLACK_M, 'BUT-1', '5901234123457'], [self::BLACK_L, 'BUT-2', null]],
            array_map(static fn (array $row): array => [$row['id'], $row['sku'], $row['ean']], $this->rows($product)),
        );
    }

    public function testTheCodesOfTheOnlyVariantStayWhenTheLastAxisIsRemoved(): void
    {
        $product = $this->product(['Kolor'], [
            ['id' => self::BLACK_M, 'values' => ['czarne'], 'sku' => 'BUT-1', 'ean' => '5901234123457'],
            ['id' => self::BLACK_L, 'values' => ['białe'], 'sku' => 'BUT-2'],
        ], ['status' => ProductStatus::Draft]);
        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        $this->saveVariants($product->id()->toString(), [], [['id' => self::BLACK_M, 'values' => [], 'sku' => 'BUT-1', 'ean' => '5901234123457']]);
        $this->entityManager->clear();

        self::assertSame(
            [[self::BLACK_M, 'BUT-1', '5901234123457']],
            array_map(static fn (array $row): array => [$row['id'], $row['sku'], $row['ean']], $this->rows($product)),
        );
    }

    public function testTwoVariantsOfOneProductSwapTheirSkusInOneSave(): void
    {
        $product = $this->product(['Kolor'], [
            ['id' => self::BLACK_M, 'values' => ['czarne'], 'sku' => 'BUT-1'],
            ['id' => self::BLACK_L, 'values' => ['białe'], 'sku' => 'BUT-2'],
        ]);
        $this->products->save($product, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        $this->saveVariants($product->id()->toString(), ['Kolor'], [
            ['id' => self::BLACK_M, 'values' => ['czarne'], 'sku' => 'BUT-2'],
            ['id' => self::BLACK_L, 'values' => ['białe'], 'sku' => 'BUT-1'],
        ]);
        $this->entityManager->clear();

        self::assertSame([['BUT-2', null], ['BUT-1', null]], $this->codes($this->products->get($product->id())));
    }

    public function testASkuOfAVariantOfAnotherProductIsSkuAlreadyTakenAndTheTableKeepsOneVariantWithTheCode(): void
    {
        $first = $this->product([], [['id' => self::BLACK_M, 'values' => [], 'sku' => 'BUT-1']], ['slug' => 'pierwszy']);
        $second = $this->product([], [['id' => self::WHITE_M, 'values' => [], 'sku' => 'BUT-1']], ['slug' => 'drugi']);
        $this->products->save($first, null, null, new \DateTimeImmutable());

        try {
            // No check before the write: what the unique index decides when two writes race.
            $this->products->save($second, null, null, new \DateTimeImmutable());
            self::fail('Expected SkuAlreadyTaken.');
        } catch (SkuAlreadyTaken) {
        }

        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM catalog_product_variant WHERE sku = 'BUT-1'"));
        self::assertSame(self::BLACK_M, $this->connection->fetchOne("SELECT id FROM catalog_product_variant WHERE sku = 'BUT-1'"));
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM catalog_product WHERE id = ?', [$second->id()->toString()]));
    }

    public function testASkuOfAnotherProductInAnEditIsSkuAlreadyTakenAndTheEditIsNotSaved(): void
    {
        $first = $this->product([], [['id' => self::BLACK_M, 'values' => [], 'sku' => 'BUT-1']], ['slug' => 'pierwszy']);
        $second = $this->product([], [['id' => self::WHITE_M, 'values' => [], 'sku' => 'BUT-2']], ['slug' => 'drugi']);
        $this->products->save($first, null, null, new \DateTimeImmutable());
        $this->products->save($second, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();

        try {
            $this->saveVariants($second->id()->toString(), [], [['id' => self::WHITE_M, 'values' => [], 'sku' => 'but-1']]);
            self::fail('Expected SkuAlreadyTaken.');
        } catch (SkuAlreadyTaken) {
        }

        self::assertSame(
            [['BUT-1', self::BLACK_M], ['BUT-2', self::WHITE_M]],
            array_map(static fn (array $row): array => [$row['sku'], $row['id']], $this->connection->fetchAllAssociative('SELECT sku, id FROM catalog_product_variant ORDER BY sku')),
        );
    }

    public function testTheSkuOfARemovedVariantIsFreeAtOnce(): void
    {
        $first = $this->product(['Kolor'], [
            ['id' => self::BLACK_M, 'values' => ['czarne'], 'sku' => 'BUT-1'],
            ['id' => self::BLACK_L, 'values' => ['białe']],
        ], ['slug' => 'pierwszy']);
        $this->products->save($first, null, null, new \DateTimeImmutable());
        $this->entityManager->clear();
        $this->saveVariants($first->id()->toString(), ['Kolor'], [['id' => self::BLACK_L, 'values' => ['białe']]]);
        $this->entityManager->clear();

        $this->products->save($this->product([], [['id' => self::WHITE_M, 'values' => [], 'sku' => 'BUT-1']], ['slug' => 'drugi']), null, null, new \DateTimeImmutable());

        self::assertSame(self::WHITE_M, $this->connection->fetchOne("SELECT id FROM catalog_product_variant WHERE sku = 'BUT-1'"));
    }

    public function testTheSkusHeldByOtherProductsAreTheOnesOfTheirVariantsDraftsIncludedAndNeverTheOwn(): void
    {
        $draft = $this->product([], [['id' => self::BLACK_M, 'values' => [], 'sku' => 'BUT-1']], ['slug' => 'szkic', 'status' => ProductStatus::Draft]);
        $own = $this->product(['Kolor'], [
            ['id' => self::BLACK_L, 'values' => ['czarne'], 'sku' => 'BUT-2'],
            ['id' => self::WHITE_M, 'values' => ['białe'], 'sku' => 'BUT-3'],
        ], ['slug' => 'wlasny']);
        $this->products->save($draft, null, null, new \DateTimeImmutable());
        $this->products->save($own, null, null, new \DateTimeImmutable());

        $taken = $this->products->skusOfOtherProducts($own->id(), [Sku::stored('BUT-3'), Sku::stored('BUT-1'), Sku::stored('BUT-9'), Sku::stored('BUT-2')]);

        self::assertSame(['BUT-1'], array_map(static fn (Sku $sku): string => $sku->value(), $taken));
        self::assertSame([], $this->products->skusOfOtherProducts($own->id(), []));
    }

    /**
     * @param list<string>                                                              $axes
     * @param list<array{id: string, values: list<string>, sku?: string, ean?: string}> $variants
     * @param array<string, mixed>                                                      $attributes
     */
    private function product(array $axes, array $variants, array $attributes = []): Product
    {
        return ProductFactory::createOne([
            'mainCategory' => $this->category,
            'variantAxes' => $axes,
            'variants' => $variants,
            ...$attributes,
        ]);
    }

    /**
     * @param list<string>                                                              $axes
     * @param list<array{id: string, values: list<string>, sku?: string, ean?: string}> $variants
     */
    private function variants(array $axes, array $variants): ProductVariants
    {
        return ProductVariants::create(
            VariantAxes::fromList($axes),
            array_map(
                static fn (array $row): ProductVariant => new ProductVariant(
                    VariantId::fromString($row['id']),
                    VariantValues::fromList($row['values']),
                    Sku::optional($row['sku'] ?? ''),
                    Ean::optional($row['ean'] ?? ''),
                ),
                $variants,
            ),
        );
    }

    /**
     * Reads the product, edits only its variants and saves it from the view it read (a write with the expected version).
     *
     * @param list<string>                                                              $axes
     * @param list<array{id: string, values: list<string>, sku?: string, ean?: string}> $variants
     */
    private function saveVariants(string $productId, array $axes, array $variants): void
    {
        $product = $this->products->get(ProductId::fromString($productId));
        $product->edit(
            $product->name(),
            $product->slug(),
            $product->shortDescription(),
            $product->brand(),
            $product->description(),
            $product->parameters(),
            $product->categories(),
            $product->taxClass(),
            $product->images(),
            $this->variants($axes, $variants),
            [],
            new \DateTimeImmutable(),
        );
        $this->products->save($product, $product->version(), 'staff-1', new \DateTimeImmutable());
    }

    /**
     * @return list<array{id: string, sku: string|null, ean: string|null}> the rows of the variants of the product in their order
     */
    private function rows(Product $product): array
    {
        /** @var list<array{id: string, sku: string|null, ean: string|null}> */
        return $this->connection->fetchAllAssociative(
            'SELECT id, sku, ean FROM catalog_product_variant WHERE product_id = ? ORDER BY position',
            [$product->id()->toString()],
        );
    }

    /**
     * @return list<array{string|null, string|null}>
     */
    private function codes(Product $product): array
    {
        return array_map(
            static fn (ProductVariant $variant): array => [$variant->sku()?->value(), $variant->ean()?->value()],
            $product->variants()->toList(),
        );
    }
}
