<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence\Doctrine;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductImage;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductRepository;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\SlugAlreadyTaken;
use App\Catalog\Domain\Product\TaxCategory;
use App\Catalog\Infrastructure\Persistence\Doctrine\Record\CategoryRecord;
use App\Catalog\Infrastructure\Persistence\Doctrine\Record\ProductRecord;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;

final readonly class DoctrineProductRepository implements ProductRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Product $product): void
    {
        try {
            $this->entityManager->wrapInTransaction(function () use ($product): void {
                $this->write($product);
            });
        } catch (OptimisticLockException) {
            throw new ProductModifiedConcurrently();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), ProductRecord::SLUG_CONSTRAINT)) {
                throw new SlugAlreadyTaken();
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
            ->select('product', 'additional')
            ->from(ProductRecord::class, 'product')
            ->leftJoin('product.additionalCategories', 'additional')
            ->orderBy('product.slug')
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

    private function write(Product $product): void
    {
        $id = $product->id()->toString();
        $existing = $this->entityManager->find(ProductRecord::class, $id);
        $record = $existing ?? new ProductRecord($id);
        $record->slug = $product->slug()->value();
        $record->name = $product->name()->value();
        $record->shortDescription = $product->shortDescription()->value();
        $record->primaryCategory = $this->categoryReference($product->categories()->main());
        $record->taxCategory = $product->taxCategory()->value;
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

        if (null !== $existing && $categoriesChanged && !$this->rowChanged($record)) {
            // Doctrine does not touch the version when only the many-to-many collection changes.
            $this->incrementVersion($record);
        }
        if ($categoriesChanged) {
            $record->additionalCategories->clear();
            foreach ($additionalIds as $categoryId) {
                $record->additionalCategories->add($this->categoryReference(CategoryId::fromString($categoryId)));
            }
        }

        $this->entityManager->persist($record);
        $this->entityManager->flush();
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
            TaxCategory::from($record->taxCategory),
            ProductImages::fromList($images),
        );
    }
}
