<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Event\ProductChanged;
use App\Catalog\Application\Port\Clock;
use App\Catalog\Application\Port\ProductImageStorage;
use App\Catalog\Application\Port\ProductImageStorageUnavailable;
use App\Catalog\Application\Port\VariantStockFacts;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Product\DuplicateVariantValues;
use App\Catalog\Domain\Product\ImageNotFound;
use App\Catalog\Domain\Product\InvalidProductVariants;
use App\Catalog\Domain\Product\InvalidVariantAxes;
use App\Catalog\Domain\Product\InvalidVariantValues;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductNotPublishable;
use App\Catalog\Domain\Product\ProductRepository;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\SlugAlreadyTaken;
use App\Catalog\Domain\Product\SlugLocked;
use App\Catalog\Domain\Product\SlugRequired;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Domain\Product\UnknownVariant;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantIdentityLocked;
use App\Catalog\Domain\Product\VariantInUse;
use App\Catalog\Domain\Product\VariantValues;
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class EditProductHandler
{
    private ProductInput $input;

    public function __construct(
        private ProductRepository $products,
        CategoryRepository $categories,
        private VariantStockFacts $stock,
        ProductImageStorage $imageStorage,
        private DomainEventPublisher $events,
        private Clock $clock,
    ) {
        $this->input = new ProductInput($categories, $imageStorage);
    }

    /**
     * The version comes first: a stale one is refused before the variants are built, the stock is read or any rule
     * of the domain is checked. Every successful edit raises the version and publishes ProductChanged.
     *
     * @throws ProductNotFound
     * @throws ProductModifiedConcurrently                                                           when the expected version is not the stored one (checked again on write)
     * @throws CategoryNotFound                                                                      when the main or an additional category does not exist
     * @throws SlugRequired
     * @throws SlugLocked
     * @throws SlugAlreadyTaken
     * @throws UnknownVariant                                                                        when a row names a variant of another product or none
     * @throws InvalidVariantAxes|InvalidVariantValues|InvalidProductVariants|DuplicateVariantValues
     * @throws ProductNotPublishable                                                                 when the last image of a published product is removed
     * @throws VariantIdentityLocked
     * @throws VariantInUse
     * @throws ImageNotFound                                                                         when the original of an image is not stored
     * @throws ProductImageStorageUnavailable
     */
    public function __invoke(EditProduct $command): void
    {
        $product = $this->products->get(ProductId::fromString($command->productId));
        if ($command->expectedVersion !== $product->version()) {
            throw new ProductModifiedConcurrently();
        }

        $slug = '' === $command->slug && $product->isSlugLocked() ? $product->slug() : ProductInput::slug($command->slug, $command->name);
        $categories = $this->input->categories($command->mainCategoryId, $command->additionalCategoryIds);
        $variants = $this->variants($product, $command);
        $images = $this->input->images($command->images);

        $now = $this->clock->now();
        $product->edit(
            new ProductName($command->name),
            $slug,
            new ShortDescription($command->shortDescription),
            $categories,
            TaxClassCode::from($command->taxClass),
            $images,
            $variants,
            $this->variantsInUse($this->removedVariants($product, $variants)),
            $now,
        );

        $this->products->save($product, $command->expectedVersion, $command->actorId, $now);
        $this->events->publish(...ProductChanged::translate($product->releaseEvents()));
    }

    /**
     * A row with an id of the product keeps it, a row without one gets a new UUID v7.
     *
     * @throws UnknownVariant
     */
    private function variants(Product $product, EditProduct $command): ProductVariants
    {
        $known = [];
        foreach ($product->variants()->toList() as $variant) {
            $known[$variant->id()->toString()] = true;
        }

        $variants = [];
        foreach ($command->variants as $index => $row) {
            if (null === $row['variantId']) {
                $id = VariantId::fromString(Uuid::v7()->toRfc4122());
            } else {
                $id = VariantId::fromString($row['variantId']);
                if (!isset($known[$id->toString()])) {
                    throw new UnknownVariant($index);
                }
            }
            $variants[] = new ProductVariant($id, VariantValues::fromList($row['values']));
        }

        return ProductVariants::create(VariantAxes::fromList($command->variantAxes), $variants);
    }

    /**
     * @return list<string> the ids of the stored variants missing from the new ones
     */
    private function removedVariants(Product $product, ProductVariants $variants): array
    {
        $kept = array_map(static fn (ProductVariant $variant): string => $variant->id()->toString(), $variants->toList());

        return array_values(array_diff(
            array_map(static fn (ProductVariant $variant): string => $variant->id()->toString(), $product->variants()->toList()),
            $kept,
        ));
    }

    /**
     * @param list<string> $removed
     *
     * @return list<VariantId>
     */
    private function variantsInUse(array $removed): array
    {
        return [] === $removed ? [] : array_map(VariantId::fromString(...), $this->stock->inUse($removed));
    }
}
