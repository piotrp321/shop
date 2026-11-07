<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductAlreadyExists;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductRepository;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\SlugAlreadyTaken;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Domain\Product\VariantAlreadyExists;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;
use App\Catalog\Infrastructure\Persistence\Doctrine\Record\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\Doctrine\Record\ProductRecord;
use App\Catalog\Infrastructure\Persistence\Doctrine\Record\ProductVariantRecord;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

final readonly class DoctrineProductRepository implements ProductRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Product $product, ?int $expectedVersion, ?string $actorId, \DateTimeImmutable $at): void
    {
        $this->refuseVariantIdOfAnotherKnownProduct($product);

        try {
            $this->entityManager->wrapInTransaction(function () use ($product, $expectedVersion, $actorId, $at): void {
                $this->write($product, $expectedVersion, $actorId, $at);
            });
        } catch (OptimisticLockException) {
            throw new ProductModifiedConcurrently();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), ProductRecord::SLUG_CONSTRAINT)) {
                throw new SlugAlreadyTaken();
            }
            if (str_contains($e->getMessage(), ProductVariantRecord::PRIMARY_KEY_CONSTRAINT)) {
                throw new VariantAlreadyExists();
            }
            if (str_contains($e->getMessage(), ProductRecord::PRIMARY_KEY_CONSTRAINT)) {
                throw new ProductAlreadyExists();
            }

            throw $e;
        }
    }

    public function get(ProductId $id): Product
    {
        $record = $this->entityManager->find(ProductRecord::class, $id->toString());
        if (null === $record) {
            throw new ProductNotFound();
        }

        return $this->toDomain($record);
    }

    public function all(): iterable
    {
        $records = $this->entityManager->createQueryBuilder()
            ->select('product', 'additional', 'variant')
            ->from(ProductRecord::class, 'product')
            ->leftJoin('product.additionalCategories', 'additional')
            ->leftJoin('product.variants', 'variant')
            ->orderBy('product.slug')
            ->addOrderBy('variant.position')
            ->getQuery()
            ->getResult();

        foreach ($records as $record) {
            yield $this->toDomain($record);
        }
    }

    public function count(): int
    {
        return $this->entityManager->getRepository(ProductRecord::class)->count();
    }

    public function countWithoutImages(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM catalog_product WHERE images = '[]'::jsonb",
        );
        \assert(\is_int($count));

        return $count;
    }

    /**
     * A variant id that another product of this EntityManager already holds would collide in its identity map before
     * the primary key of the table is reached (the case of a seed that saves many products in one process).
     */
    private function refuseVariantIdOfAnotherKnownProduct(Product $product): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        foreach ($product->variants()->toList() as $variant) {
            $known = $unitOfWork->tryGetById($variant->id()->toString(), ProductVariantRecord::class);
            if ($known instanceof ProductVariantRecord && $known->product->id !== $product->id()->toString()) {
                throw new VariantAlreadyExists();
            }
        }
    }

    private function write(Product $product, ?int $expectedVersion, ?string $actorId, \DateTimeImmutable $at): void
    {
        $id = $product->id()->toString();
        $existing = $this->entityManager->find(ProductRecord::class, $id);
        if (null !== $existing && null !== $expectedVersion && $existing->version !== $expectedVersion) {
            throw new ProductModifiedConcurrently();
        }
        $record = $existing ?? new ProductRecord($id);
        $record->slug = $product->slug()->value();
        $record->name = $product->name()->value();
        $record->shortDescription = $product->shortDescription()->value();
        $record->primaryCategory = $this->categoryReference($product->categories()->main());
        $record->taxClass = $product->taxClass()->value;
        $record->status = $product->status()->value;
        // Compared by value: Doctrine sees a new DateTimeImmutable instance as a change. The column keeps whole seconds.
        $firstPublishedAt = $product->firstPublishedAt();
        if ($record->firstPublishedAt?->getTimestamp() !== $firstPublishedAt?->getTimestamp()) {
            $record->firstPublishedAt = $firstPublishedAt;
        }
        $images = array_map(
            static fn (ProductImage $image): array => ['key' => $image->key()->toString(), 'alt' => $image->alt()],
            $product->images()->toList(),
        );
        // JSONB returns object keys in its own order, so compare the stored entries rebuilt in our key order, strictly.
        $stored = null === $existing ? null : array_map(
            static fn (array $image): array => ['key' => $image['key'], 'alt' => $image['alt']],
            $record->images,
        );
        if ($stored !== $images) {
            $record->images = $images;
        }

        $additionalIds = array_map(static fn (CategoryId $categoryId): string => $categoryId->toString(), $product->categories()->additional());
        sort($additionalIds);
        $currentIds = $record->additionalCategories
            ->map(static fn (CategoryRecord $category): string => $category->id)
            ->toArray();
        sort($currentIds);
        $categoriesChanged = $additionalIds !== $currentIds;

        $axes = $product->variantAxes()->names();
        if (null === $existing || $record->variantAxes !== $axes) {
            $record->variantAxes = $axes;
        }
        $variants = array_map(
            static fn (ProductVariant $variant): array => ['id' => $variant->id()->toString(), 'values' => $variant->values()->toList()],
            $product->variants()->toList(),
        );
        $variantsChanged = null !== $existing && $variants !== $this->storedVariants($record);

        // A write from a view (expectedVersion) always raises the version; another write only when something changed.
        $raisesVersion = null === $existing || null !== $expectedVersion || $categoriesChanged || $variantsChanged || $this->rowChanged($record);
        if ($raisesVersion) {
            $record->updatedAt = $at;
            $record->updatedBy = $actorId;
        }
        if (null !== $existing && $raisesVersion && !$this->rowChanged($record)) {
            // Doctrine does not touch the version when only a collection changes, nor when the row is the same.
            $this->incrementVersion($record);
        }
        if ($categoriesChanged) {
            $record->additionalCategories->clear();
            foreach ($additionalIds as $categoryId) {
                $record->additionalCategories->add($this->categoryReference(CategoryId::fromString($categoryId)));
            }
        }

        if (null === $existing) {
            $this->addVariants($record, $variants);
        }

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        if ($variantsChanged) {
            // The old rows go first: the new ones may reuse a position, a combination or an id.
            $record->variants->clear();
            $this->entityManager->flush();
            $this->addVariants($record, $variants);
            $this->entityManager->flush();
        }
    }

    /**
     * @return list<array{id: string, values: list<string>}>
     */
    private function storedVariants(ProductRecord $record): array
    {
        return array_values($record->variants->map(
            static fn (ProductVariantRecord $variant): array => ['id' => $variant->id, 'values' => $variant->optionValues],
        )->toArray());
    }

    /**
     * @param list<array{id: string, values: list<string>}> $variants
     */
    private function addVariants(ProductRecord $record, array $variants): void
    {
        foreach ($variants as $position => $variant) {
            $variantRecord = new ProductVariantRecord($variant['id'], $record, $position);
            $variantRecord->optionValues = $variant['values'];
            $record->variants->add($variantRecord);
        }
    }

    private function rowChanged(ProductRecord $record): bool
    {
        // Relies on flush() running right after it in write(): recomputeSingleEntityChangeSet is an API meant for flush listeners.
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->recomputeSingleEntityChangeSet($this->entityManager->getClassMetadata(ProductRecord::class), $record);

        return [] !== $unitOfWork->getEntityChangeSet($record);
    }

    private function incrementVersion(ProductRecord $record): void
    {
        $affected = $this->entityManager->getConnection()->executeStatement(
            'UPDATE catalog_product SET version = version + 1 WHERE id = :id AND version = :version',
            ['id' => $record->id, 'version' => $record->version],
        );
        if (0 === $affected) {
            throw new ProductModifiedConcurrently();
        }
        ++$record->version;
    }

    private function categoryReference(CategoryId $id): CategoryRecord
    {
        $reference = $this->entityManager->getReference(CategoryRecord::class, $id->toString());
        \assert(null !== $reference);

        return $reference;
    }

    private function toDomain(ProductRecord $record): Product
    {
        // Sorted here, not by the mapping: a record from the identity map keeps the order it was saved in.
        $additionalIds = $record->additionalCategories
            ->map(static fn (CategoryRecord $category): string => $category->id)
            ->toArray();
        sort($additionalIds);

        $images = [];
        foreach ($record->images as $position => $image) {
            $images[] = new ProductImage(ImageKey::fromString($image['key']), $image['alt'], $position);
        }

        return Product::reconstitute(
            ProductId::fromString($record->id),
            new ProductName($record->name),
            new Slug($record->slug),
            new ShortDescription($record->shortDescription),
            ProductCategories::create(
                CategoryId::fromString($record->primaryCategory->id),
                array_map(CategoryId::fromString(...), $additionalIds),
            ),
            TaxClassCode::from($record->taxClass),
            ProductImages::fromList($images),
            ProductVariants::create(
                VariantAxes::fromList($record->variantAxes),
                array_values($record->variants->map(
                    static fn (ProductVariantRecord $variant): ProductVariant => new ProductVariant(
                        VariantId::fromString($variant->id),
                        VariantValues::fromList($variant->optionValues),
                    ),
                )->toArray()),
            ),
            ProductStatus::from($record->status),
            $record->firstPublishedAt,
            $record->version,
        );
    }
}
