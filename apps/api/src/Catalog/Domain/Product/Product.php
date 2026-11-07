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
     * Replaces the data of the product and records ProductChanged, also when nothing differs (consumers handle it
     * idempotently). Checks before changing anything: the slug is locked from the first publication (K4); a published
     * product that has images keeps at least one (K2); after the first publication the variants keep their identity
     * (K5); a variant in use cannot be removed (K5).
     *
     * @param list<VariantId> $variantsInUse the variants with physical or reserved stock; removing one is refused
     *
     * @throws SlugLocked
     * @throws ProductNotPublishable
     * @throws VariantIdentityLocked
     * @throws VariantInUse
     */
    public function edit(
        ProductName $name,
        Slug $slug,
        ShortDescription $shortDescription,
        ProductCategories $categories,
        TaxClassCode $taxClass,
        ProductImages $images,
        ProductVariants $variants,
        array $variantsInUse,
        \DateTimeImmutable $now,
    ): void {
        if ($this->isSlugLocked() && $slug->value() !== $this->slug->value()) {
            throw new SlugLocked();
        }
        if (ProductStatus::Published === $this->status && !$this->images->isEmpty() && $images->isEmpty()) {
            throw ProductNotPublishable::lastImageRemoved();
        }
        if ($this->isSlugLocked()) {
            $this->refuseChangedIdentity($variants);
        }
        $this->refuseRemovalOfVariantsInUse($variants, $variantsInUse);

        $this->name = $name;
        $this->slug = $slug;
        $this->shortDescription = $shortDescription;
        $this->categories = $categories;
        $this->taxClass = $taxClass;
        $this->images = $images;
        $this->variants = $variants;
        $this->recordChange($now);
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

    /**
     * The same axes in the same order, each name with the same comparison key; every kept variant with the same
     * comparison key of its values. New variants are free.
     *
     * @throws VariantIdentityLocked
     */
    private function refuseChangedIdentity(ProductVariants $variants): void
    {
        $stored = $this->variants->axes()->names();
        $given = $variants->axes()->names();
        $storedKeys = array_map(mb_strtolower(...), $stored);
        $givenKeys = array_map(mb_strtolower(...), $given);
        if (\count($stored) !== \count($given)) {
            throw VariantIdentityLocked::axes();
        }
        foreach ($storedKeys as $position => $key) {
            if ($key === $givenKeys[$position]) {
                continue;
            }
            // A name that is still there, in another place, is a reordering; otherwise the axis was renamed.
            throw \in_array($givenKeys[$position], $storedKeys, true) ? VariantIdentityLocked::axes() : VariantIdentityLocked::axisName($stored[$position]);
        }

        $storedVariants = $this->variantsById();
        foreach ($variants->toList() as $variant) {
            $kept = $storedVariants[$variant->id()->toString()] ?? null;
            if (null !== $kept && $kept->values()->comparisonKey() !== $variant->values()->comparisonKey()) {
                throw VariantIdentityLocked::variant($kept->label());
            }
        }
    }

    /**
     * @param list<VariantId> $variantsInUse
     *
     * @throws VariantInUse naming the first removed variant in use, in the order of the stored variants
     */
    private function refuseRemovalOfVariantsInUse(ProductVariants $variants, array $variantsInUse): void
    {
        $inUse = [];
        foreach ($variantsInUse as $id) {
            $inUse[$id->toString()] = true;
        }
        $kept = [];
        foreach ($variants->toList() as $variant) {
            $kept[$variant->id()->toString()] = true;
        }
        foreach ($this->variants->toList() as $variant) {
            $id = $variant->id()->toString();
            if (!isset($kept[$id]) && isset($inUse[$id])) {
                throw new VariantInUse($variant->label());
            }
        }
    }

    /**
     * @return array<string, ProductVariant>
     */
    private function variantsById(): array
    {
        $byId = [];
        foreach ($this->variants->toList() as $variant) {
            $byId[$variant->id()->toString()] = $variant;
        }

        return $byId;
    }

    private function recordChange(\DateTimeImmutable $now): void
    {
        $this->events[] = new ProductChanged($this->id->toString(), $this->slug->value(), $now);
    }
}
