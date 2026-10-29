<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategoryRepository;
use App\Catalog\Domain\Product\ImageKey;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductAlreadyExists;
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
use App\Shared\Domain\Event\DomainEventPublisher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateProductHandler
{
    public function __construct(
        private ProductRepository $products,
        private CategoryRepository $categories,
        private DomainEventPublisher $events,
    ) {
    }

    /**
     * @throws ProductAlreadyExists
     * @throws CategoryNotFound     when the main or an additional category does not exist
     * @throws SlugAlreadyTaken
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

        $categories = ProductCategories::create(
            CategoryId::fromString($command->mainCategoryId),
            array_map(CategoryId::fromString(...), $command->additionalCategoryIds),
        );
        foreach ($categories->all() as $categoryId) {
            $this->categories->get($categoryId);
        }

        $images = [];
        foreach ($command->images as $position => $image) {
            $images[] = new ProductImage(ImageKey::fromString($image['key']), $image['alt'], $position);
        }

        $product = Product::create(
            $id,
            new ProductName($command->name),
            new Slug($command->slug),
            new ShortDescription($command->shortDescription),
            $categories,
            TaxCategory::from($command->taxCategory),
            ProductImages::fromList($images),
        );

        $this->products->save($product);
        $this->events->publish(...$product->releaseEvents());
    }
}
