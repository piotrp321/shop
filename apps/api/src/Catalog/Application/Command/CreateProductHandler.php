<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Event\IntegrationEvents;
use App\Catalog\Application\Port\Clock;
use App\Catalog\Application\Port\ProductImageStorage;
use App\Catalog\Application\Port\ProductImageStorageUnavailable;
use App\Catalog\Application\Port\SearchIndexRebuildLock;
use App\Catalog\Application\Search\SearchIndexRebuildRunning;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Product\DuplicateVariantValues;
use App\Catalog\Domain\Product\Ean;
use App\Catalog\Domain\Product\ImageNotFound;
use App\Catalog\Domain\Product\InvalidProductVariants;
use App\Catalog\Domain\Product\InvalidVariantAxes;
use App\Catalog\Domain\Product\InvalidVariantValues;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductAlreadyExists;
use App\Catalog\Domain\Product\ProductBrand;
use App\Catalog\Domain\Product\ProductDescription;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductRepository;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Sku;
use App\Catalog\Domain\Product\SkuRepeated;
use App\Catalog\Domain\Product\SkuUsedByAnotherProduct;
use App\Catalog\Domain\Product\SlugAlreadyTaken;
use App\Catalog\Domain\Product\SlugRequired;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Domain\Product\UnknownCategory;
use App\Catalog\Domain\Product\VariantAlreadyExists;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateProductHandler
{
    private ProductInput $input;

    public function __construct(
        private ProductRepository $products,
        CategoryRepository $categories,
        ProductImageStorage $imageStorage,
        private DomainEventPublisher $events,
        private Clock $clock,
        private SearchIndexRebuildLock $rebuildLock,
    ) {
        $this->input = new ProductInput($categories, $imageStorage);
    }

    /**
     * Creates the product in the given status; publishes ProductCreated (never ProductChanged).
     *
     * @throws ProductAlreadyExists
     * @throws UnknownCategory                when the main or an additional category does not exist
     * @throws SlugRequired                   when the slug is empty and the name gives none
     * @throws SlugAlreadyTaken
     * @throws InvalidVariantAxes
     * @throws InvalidVariantValues
     * @throws InvalidProductVariants
     * @throws DuplicateVariantValues
     * @throws SkuRepeated                    at the later of two rows with the same SKU
     * @throws SkuUsedByAnotherProduct        at the row whose SKU a variant of another product has
     * @throws VariantAlreadyExists
     * @throws ImageNotFound                  when the original of an image is not stored
     * @throws ProductImageStorageUnavailable
     * @throws SearchIndexRebuildRunning      while the search index is rebuilt (the share lasts to the end of the transaction of command.bus)
     */
    public function __invoke(CreateProduct $command): void
    {
        if (!$this->rebuildLock->tryShareInTransaction()) {
            throw new SearchIndexRebuildRunning();
        }

        $id = ProductId::fromString($command->id);

        // save() overwrites a product with the same id, so creation has to refuse an existing one first.
        try {
            $this->products->get($id);
            throw new ProductAlreadyExists();
        } catch (ProductNotFound) {
        }

        $slug = ProductInput::slug($command->slug, $command->name);
        $categories = $this->input->categories($command->mainCategoryId, $command->additionalCategoryIds);
        $variants = ProductVariants::create(
            VariantAxes::fromList($command->variantAxes),
            array_map(
                static fn (array $variant): ProductVariant => new ProductVariant(
                    VariantId::fromString($variant['id']),
                    VariantValues::fromList($variant['values']),
                    Sku::optional($variant['sku']),
                    Ean::optional($variant['ean']),
                ),
                $command->variants,
            ),
        );
        ProductInput::refuseSkusOfOtherProducts($this->products, $id, $variants);

        $images = $this->input->images($command->images);

        $now = $this->clock->now();
        $product = Product::create(
            $id,
            ProductName::of($command->name),
            $slug,
            ShortDescription::of($command->shortDescription),
            ProductBrand::of($command->brand),
            ProductDescription::of($command->description),
            ProductInput::parameters($command->parameters),
            $categories,
            TaxClassCode::from($command->taxClass),
            $images,
            $variants,
            $command->status,
            $now,
        );

        $this->products->save($product, null, $command->actorId, $now);
        $this->events->publish(...IntegrationEvents::translate($product->releaseEvents()));
    }
}
