<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Event\DomainEvent;

/**
 * A product of the catalog with its status (decision K1). The version is the stored version the state was read at
 * (0 for a product not stored yet); the repository checks it on save, the domain never changes it.
 */
final class Product
{
    /** @var list<DomainEvent> */
    private array $events = [];

    private function __construct(
        private readonly ProductId $id,
        private ProductName $name,
        private Slug $slug,
        private ShortDescription $shortDescription,
        private ProductCategories $categories,
        private TaxClassCode $taxClass,
        private ProductImages $images,
        private ProductVariants $variants,
        private ProductStatus $status,
        private ?\DateTimeImmutable $firstPublishedAt,
        private readonly int $version,
    ) {
    }

    public static function create(
        ProductId $id,
        ProductName $name,
        Slug $slug,
        ShortDescription $shortDescription,
        ProductCategories $categories,
        TaxClassCode $taxClass,
        ProductImages $images,
        ProductVariants $variants,
        ProductStatus $status,
        \DateTimeImmutable $now,
    ): self {
        // No image rule here: the demo data publishes products without images (K2).
        $firstPublishedAt = ProductStatus::Published === $status ? $now : null;
        $product = new self($id, $name, $slug, $shortDescription, $categories, $taxClass, $images, $variants, $status, $firstPublishedAt, 0);
        $product->events[] = new ProductCreated($id->toString(), $now);

        return $product;
    }

    /**
     * Restores a stored product without recording events.
     */
    public static function reconstitute(
        ProductId $id,
        ProductName $name,
        Slug $slug,
        ShortDescription $shortDescription,
        ProductCategories $categories,
        TaxClassCode $taxClass,
        ProductImages $images,
        ProductVariants $variants,
        ProductStatus $status,
        ?\DateTimeImmutable $firstPublishedAt,
        int $version,
    ): self {
        return new self($id, $name, $slug, $shortDescription, $categories, $taxClass, $images, $variants, $status, $firstPublishedAt, $version);
    }

    /**
     * A draft becomes published; the first publication is kept from the first time on. A published product stays as
     * it is and records nothing.
     *
     * @throws ProductNotPublishable when a draft has no image
     */
    public function publish(\DateTimeImmutable $now): void
    {
        if (ProductStatus::Published === $this->status) {
            return;
        }
        if ($this->images->isEmpty()) {
            throw ProductNotPublishable::withoutImages();
        }

        $this->status = ProductStatus::Published;
        $this->firstPublishedAt ??= $now;
        $this->recordChange($now);
    }

    /**
     * A published product becomes a draft again; its first publication stays (the slug stays locked). A draft stays
     * as it is and records nothing.
     */
    public function unpublish(\DateTimeImmutable $now): void
    {
        if (ProductStatus::Draft === $this->status) {
            return;
        }

        $this->status = ProductStatus::Draft;
        $this->recordChange($now);
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function id(): ProductId
    {
        return $this->id;
    }

    public function name(): ProductName
    {
        return $this->name;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function shortDescription(): ShortDescription
    {
        return $this->shortDescription;
    }

    public function categories(): ProductCategories
    {
        return $this->categories;
    }

    public function taxClass(): TaxClassCode
    {
        return $this->taxClass;
    }

    public function images(): ProductImages
    {
        return $this->images;
    }

    public function variantAxes(): VariantAxes
    {
        return $this->variants->axes();
    }

    public function variants(): ProductVariants
    {
        return $this->variants;
    }

    public function status(): ProductStatus
    {
        return $this->status;
    }

    public function firstPublishedAt(): ?\DateTimeImmutable
    {
        return $this->firstPublishedAt;
    }

    /**
     * The slug is fixed from the first publication on, also after an unpublication (decision K4).
     */
    public function isSlugLocked(): bool
    {
        return null !== $this->firstPublishedAt;
    }

    public function version(): int
    {
        return $this->version;
    }

    private function recordChange(\DateTimeImmutable $now): void
    {
        $this->events[] = new ProductChanged($this->id->toString(), $this->slug->value(), $now);
    }
}
