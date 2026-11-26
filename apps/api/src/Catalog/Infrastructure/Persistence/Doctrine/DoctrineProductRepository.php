<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\Ean;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductAlreadyExists;
use App\Catalog\Domain\Product\ProductBrand;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductDescription;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductParameter;
use App\Catalog\Domain\Product\ProductParameters;
use App\Catalog\Domain\Product\ProductRepository;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Sku;
use App\Catalog\Domain\Product\SkuAlreadyTaken;
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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

final readonly class DoctrineProductRepository implements ProductRepository
{
    /**
     * The first key of the two-key advisory locks of the SKUs (pg_advisory_xact_lock(int, int)), chosen for them; the second
     * is a hash of the SKU, so two SKUs with the same hash only wait for each other.
     */
    public const int SKU_LOCK_NAMESPACE = 0x534B; // "SK"

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
            if (str_contains($e->getMessage(), ProductVariantRecord::SKU_CONSTRAINT)) {
                throw new SkuAlreadyTaken();
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

    public function skusOfOtherProducts(ProductId $product, array $skus): array
    {
        if ([] === $skus) {
            return [];
        }

        $taken = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT sku FROM catalog_product_variant WHERE sku IN (:skus) AND product_id <> CAST(:product AS uuid)',
            ['skus' => array_map(static fn (Sku $sku): string => $sku->value(), $skus), 'product' => $product->toString()],
            ['skus' => ArrayParameterType::STRING],
        );

        return array_values(array_filter($skus, static fn (Sku $sku): bool => \in_array($sku->value(), $taken, true)));
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
        $this->lockSkus($product, $existing);
        $record = $existing ?? new ProductRecord($id);
        $record->slug = $product->slug()->value();
        $record->name = $product->name()->value();
        $record->shortDescription = $product->shortDescription()->value();
        $record->brand = $product->brand()->value();
        $record->description = $product->description()->value();
        $parameters = array_map(
            static fn (ProductParameter $parameter): array => ['name' => $parameter->name(), 'value' => $parameter->value()],
            $product->parameters()->toList(),
        );
        // JSONB returns object keys in its own order, so compare the stored entries rebuilt in our key order, strictly.
        $storedParameters = null === $existing ? null : array_map(
            static fn (array $parameter): array => ['name' => $parameter['name'], 'value' => $parameter['value']],
            $record->parameters,
        );
        if ($storedParameters !== $parameters) {
            $record->parameters = $parameters;
        }
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
            static fn (ProductVariant $variant): array => [
                'id' => $variant->id()->toString(),
                'values' => $variant->values()->toList(),
                'sku' => $variant->sku()?->value(),
                'ean' => $variant->ean()?->value(),
            ],
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
            $this->addVariants($record, $product->variants()->toList());
        }

        $this->entityManager->persist($record);
        $this->entityManager->flush();

        if ($variantsChanged) {
            // The old rows go first: the new ones may reuse a position, a combination or an id.
            $record->variants->clear();
            $this->entityManager->flush();
            $this->addVariants($record, $product->variants()->toList());
            $this->entityManager->flush();
        }
    }

    /**
     * Takes, before any row of the write is touched, an advisory lock of the transaction on every SKU it inserts or removes
     * (the SKUs of the variants given and of those stored), one statement, in ascending order of the keys. Two writes that
     * touch one SKU then queue here instead of on the unique index, where a row removed and not yet committed holds the
     * SKU for an insert of another write: each could wait for a key the other holds (a deadlock, a 500), whatever the
     * order the rows are written in. Whoever comes second goes on after the commit of the first and meets the committed
     * state: the SKU taken (`SkuAlreadyTaken`) or free.
     */
    private function lockSkus(Product $product, ?ProductRecord $existing): void
    {
        $skus = array_map(static fn (ProductVariant $variant): ?string => $variant->sku()?->value(), $product->variants()->toList());
        if (null !== $existing) {
            foreach ($existing->variants as $stored) {
                $skus[] = $stored->sku;
            }
        }
        $keys = [];
        foreach ($skus as $sku) {
            if (null !== $sku) {
                $hash = crc32($sku);
                // The CRC-32 as a signed 32-bit integer (the int of PostgreSQL).
                $keys[$hash > 0x7FFFFFFF ? $hash - 0x100000000 : $hash] = true;
            }
        }
        if ([] === $keys) {
            return;
        }
        $keys = array_keys($keys);
        sort($keys);

        $this->entityManager->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(CAST(:namespace AS int), k.key)
             FROM unnest(CAST(:keys AS int[])) WITH ORDINALITY AS k(key, position)
             ORDER BY k.position',
            ['namespace' => self::SKU_LOCK_NAMESPACE, 'keys' => '{'.implode(',', $keys).'}'],
        );
    }

    /**
     * @return list<array{id: string, values: list<string>, sku: string|null, ean: string|null}>
     */
    private function storedVariants(ProductRecord $record): array
    {
        return array_values($record->variants->map(
            static fn (ProductVariantRecord $variant): array => ['id' => $variant->id, 'values' => $variant->optionValues, 'sku' => $variant->sku, 'ean' => $variant->ean],
        )->toArray());
    }

    /**
     * The key of the unique index is the comparison key of the domain, so the database never rejects a combination
     * the domain accepts (lower() of PostgreSQL maps e.g. "İ" and "i" to the same text, mb_strtolower does not).
     *
     * The rows are inserted in the order of their SKUs, not of their positions: a write that holds the codes X and then Y
     * while another one holds Y and then X would deadlock on the unique index of the SKU (a 500). When every write takes
     * the codes in the same order, the later one waits for the first and then loses with `SkuAlreadyTaken`. Rows without
     * a SKU lock nothing and come last.
     *
     * @param list<ProductVariant> $variants
     */
    private function addVariants(ProductRecord $record, array $variants): void
    {
        $rows = [];
        foreach ($variants as $position => $variant) {
            $variantRecord = new ProductVariantRecord($variant->id()->toString(), $record, $position);
            $variantRecord->optionValues = $variant->values()->toList();
            $variantRecord->sku = $variant->sku()?->value();
            $variantRecord->ean = $variant->ean()?->value();
            $variantRecord->valuesKey = json_encode($variant->values()->comparisonKey(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
            $record->variants->add($variantRecord);
            $rows[] = $variantRecord;
        }

        usort($rows, static fn (ProductVariantRecord $left, ProductVariantRecord $right): int => match (true) {
            null === $left->sku && null === $right->sku => $left->position <=> $right->position,
            null === $left->sku => 1,
            null === $right->sku => -1,
            default => strcmp($left->sku, $right->sku),
        });
        // Scheduled one by one in this order; the cascade of the product then finds them scheduled already.
        foreach ($rows as $row) {
            $this->entityManager->persist($row);
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
            $images[] = ProductImage::stored(ImageKey::fromString($image['key']), $image['alt'], $position);
        }

        return Product::reconstitute(
            ProductId::fromString($record->id),
            ProductName::stored($record->name),
            new Slug($record->slug),
            ShortDescription::stored($record->shortDescription),
            ProductBrand::stored($record->brand),
            ProductDescription::stored($record->description),
            ProductParameters::stored(array_map(
                static fn (array $parameter): ProductParameter => ProductParameter::stored($parameter['name'], $parameter['value']),
                $record->parameters,
            )),
            ProductCategories::create(
                CategoryId::fromString($record->primaryCategory->id),
                array_map(CategoryId::fromString(...), $additionalIds),
            ),
            TaxClassCode::from($record->taxClass),
            ProductImages::fromList($images),
            ProductVariants::create(
                VariantAxes::stored($record->variantAxes),
                array_values($record->variants->map(
                    static fn (ProductVariantRecord $variant): ProductVariant => new ProductVariant(
                        VariantId::fromString($variant->id),
                        VariantValues::stored($variant->optionValues),
                        null === $variant->sku ? null : Sku::stored($variant->sku),
                        null === $variant->ean ? null : Ean::stored($variant->ean),
                    ),
                )->toArray()),
            ),
            ProductStatus::from($record->status),
            $record->firstPublishedAt,
            $record->version,
        );
    }
}
