<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Event\DomainEvent;

final class Product
{
    /** @var list<DomainEvent> */
    private array $events = [];

    private function __construct(
        private readonly ProductId $id,
        private readonly ProductName $name,
        private readonly Slug $slug,
        private readonly ShortDescription $shortDescription,
        private readonly ProductCategories $categories,
        private readonly TaxCategory $taxCategory,
        private readonly ProductImages $images,
    ) {
    }

    public static function create(
        ProductId $id,
        ProductName $name,
        Slug $slug,
        ShortDescription $shortDescription,
        ProductCategories $categories,
        TaxCategory $taxCategory,
        ProductImages $images,
    ): self {
        $product = new self($id, $name, $slug, $shortDescription, $categories, $taxCategory, $images);
        $product->events[] = new ProductCreated($id->toString(), new \DateTimeImmutable());

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
        TaxCategory $taxCategory,
        ProductImages $images,
    ): self {
        return new self($id, $name, $slug, $shortDescription, $categories, $taxCategory, $images);
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

    public function taxCategory(): TaxCategory
    {
        return $this->taxCategory;
    }

    public function images(): ProductImages
    {
        return $this->images;
    }
}
