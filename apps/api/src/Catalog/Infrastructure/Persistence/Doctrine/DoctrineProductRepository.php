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

final readonly class DoctrineProductRepository implements ProductRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Product $product): void
    {
        $id = $product->id()->toString();
        $record = $this->entityManager->find(ProductRecord::class, $id) ?? new ProductRecord($id);
        $record->slug = $product->slug()->value();
        $record->name = $product->name()->value();
        $record->shortDescription = $product->shortDescription()->value();
        $record->primaryCategory = $this->categoryReference($product->categories()->main());
        $record->additionalCategories->clear();
        foreach ($product->categories()->additional() as $categoryId) {
            $record->additionalCategories->add($this->categoryReference($categoryId));
        }
        $record->taxCategory = $product->taxCategory()->value;
        $record->images = array_map(
            static fn (ProductImage $image): array => ['key' => $image->key()->toString(), 'alt' => $image->alt()],
            $product->images()->toList(),
        );

        $this->entityManager->persist($record);
        try {
            $this->entityManager->flush();
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

    private function categoryReference(CategoryId $id): CategoryRecord
    {
        $reference = $this->entityManager->getReference(CategoryRecord::class, $id->toString());
        \assert(null !== $reference);

        return $reference;
    }

    private function toDomain(ProductRecord $record): Product
    {
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
                array_values($record->additionalCategories->map(
                    static fn (CategoryRecord $category): CategoryId => CategoryId::fromString($category->id),
                )->toArray()),
            ),
            TaxCategory::from($record->taxCategory),
            ProductImages::fromList($images),
        );
    }
}
