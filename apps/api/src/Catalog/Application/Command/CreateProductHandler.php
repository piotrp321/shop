<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Event\ProductChanged;
use App\Catalog\Application\Port\Clock;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Product\DuplicateVariantValues;
use App\Catalog\Domain\Product\InvalidProductVariants;
use App\Catalog\Domain\Product\InvalidVariantAxes;
use App\Catalog\Domain\Product\InvalidVariantValues;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductAlreadyExists;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductRepository;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\SlugAlreadyTaken;
use App\Catalog\Domain\Product\SlugRequired;
use App\Catalog\Domain\Product\TaxClassCode;
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
        private DomainEventPublisher $events,
        private Clock $clock,
    ) {
        $this->input = new ProductInput($categories);
    }

    /**
     * Creates the product in the given status; publishes ProductCreated (never ProductChanged).
     *
     * @throws ProductAlreadyExists
     * @throws CategoryNotFound       when the main or an additional category does not exist
     * @throws SlugRequired           when the slug is empty and the name gives none
     * @throws SlugAlreadyTaken
     * @throws InvalidVariantAxes
     * @throws InvalidVariantValues
     * @throws InvalidProductVariants
     * @throws DuplicateVariantValues
     * @throws VariantAlreadyExists
     */
    public function __invoke(CreateProduct $command): void
    {
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
                static fn (array $variant): ProductVariant => new ProductVariant(VariantId::fromString($variant['id']), VariantValues::fromList($variant['values'])),
                $command->variants,
            ),
        );

        $now = $this->clock->now();
        $product = Product::create(
            $id,
            new ProductName($command->name),
            $slug,
            new ShortDescription($command->shortDescription),
            $categories,
            TaxClassCode::from($command->taxClass),
            $this->input->images($command->images),
            $variants,
            $command->status,
            $now,
        );

        $this->products->save($product, null, $command->actorId, $now);
        $this->events->publish(...ProductChanged::translate($product->releaseEvents()));
    }
}
