<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Command\CreateProductHandler;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Product\ProductAlreadyExists;
use App\Catalog\Domain\Product\ProductCreated;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\SlugAlreadyTaken;
use App\Catalog\Domain\Product\TaxCategory;
use App\Tests\Support\InMemory\InMemoryCategoryRepository;
use App\Tests\Support\InMemory\InMemoryProductRepository;
use App\Tests\Support\InMemory\RecordingDomainEventPublisher;
use PHPUnit\Framework\TestCase;

final class CreateProductHandlerTest extends TestCase
{
    private const string PRODUCT_ID = '10000000-0000-4000-8000-000000000001';
    private const string MAIN_ID = '00000000-0000-4000-8000-000000000001';
    private const string EXTRA_ID = '00000000-0000-4000-8000-000000000002';
    private const string UNKNOWN_ID = '00000000-0000-4000-8000-0000000000ff';

    private InMemoryCategoryRepository $categories;
    private InMemoryProductRepository $products;
    private RecordingDomainEventPublisher $events;
    private CreateProductHandler $handler;

    protected function setUp(): void
    {
        $this->categories = new InMemoryCategoryRepository();
        $this->categories->save(Category::createRoot(CategoryId::fromString(self::MAIN_ID), new CategoryName('Sport')));
        $this->categories->save(Category::createRoot(CategoryId::fromString(self::EXTRA_ID), new CategoryName('Obuwie')));
        $this->products = new InMemoryProductRepository();
        $this->events = new RecordingDomainEventPublisher();
        $this->handler = new CreateProductHandler($this->products, $this->categories, $this->events);
    }

    public function testProductWithMainAndAdditionalCategoryIsSavedAndProductCreatedPublishedOnce(): void
    {
        ($this->handler)($this->command());

        $product = $this->products->get(ProductId::fromString(self::PRODUCT_ID));
        self::assertSame('Buty biegowe', $product->name()->value());
        self::assertSame('buty-biegowe', $product->slug()->value());
        self::assertSame('Lekkie', $product->shortDescription()->value());
        self::assertSame(self::MAIN_ID, $product->categories()->main()->toString());
        self::assertSame([self::EXTRA_ID], array_map(static fn (CategoryId $id): string => $id->toString(), $product->categories()->additional()));
        self::assertSame(TaxCategory::Standard, $product->taxCategory());
        $images = $product->images()->toList();
        self::assertCount(1, $images);
        self::assertSame('originals/'.str_repeat('a', 64).'.jpg', $images[0]->key()->toString());
        self::assertSame('front', $images[0]->alt());
        self::assertSame(0, $images[0]->position());

        self::assertCount(1, $this->events->published);
        $event = $this->events->published[0];
        self::assertInstanceOf(ProductCreated::class, $event);
        self::assertSame(self::PRODUCT_ID, $event->productId);
    }

    public function testUnknownMainCategoryThrowsCategoryNotFoundAndSavesNothing(): void
    {
        try {
            ($this->handler)($this->command(mainCategoryId: self::UNKNOWN_ID));
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound) {
        }

        self::assertSame(0, $this->products->count());
        self::assertSame([], $this->events->published);
    }

    public function testUnknownAdditionalCategoryThrowsCategoryNotFoundAndSavesNothing(): void
    {
        try {
            ($this->handler)($this->command(additionalCategoryIds: [self::EXTRA_ID, self::UNKNOWN_ID]));
            self::fail('Expected CategoryNotFound.');
        } catch (CategoryNotFound) {
        }

        self::assertSame(0, $this->products->count());
        self::assertSame([], $this->events->published);
    }

    public function testTakenSlugThrowsSlugAlreadyTakenAndPublishesNothing(): void
    {
        ($this->handler)($this->command());
        $this->events->published = [];

        try {
            ($this->handler)($this->command(id: '10000000-0000-4000-8000-000000000002'));
            self::fail('Expected SlugAlreadyTaken.');
        } catch (SlugAlreadyTaken) {
        }

        self::assertSame(1, $this->products->count());
        self::assertSame([], $this->events->published);
    }

    public function testExistingIdThrowsProductAlreadyExistsKeepsTheSavedProductAndPublishesNothing(): void
    {
        ($this->handler)($this->command());
        $this->events->published = [];

        try {
            ($this->handler)($this->command(name: 'Inna nazwa', slug: 'inny-slug'));
            self::fail('Expected ProductAlreadyExists.');
        } catch (ProductAlreadyExists) {
        }

        $kept = $this->products->get(ProductId::fromString(self::PRODUCT_ID));
        self::assertSame('Buty biegowe', $kept->name()->value());
        self::assertSame('buty-biegowe', $kept->slug()->value());
        self::assertSame(1, $this->products->count());
        self::assertSame([], $this->events->published);
    }

    /**
     * @param list<string> $additionalCategoryIds
     */
    private function command(
        string $id = self::PRODUCT_ID,
        string $name = 'Buty biegowe',
        string $slug = 'buty-biegowe',
        string $mainCategoryId = self::MAIN_ID,
        array $additionalCategoryIds = [self::EXTRA_ID],
    ): CreateProduct {
        return new CreateProduct(
            $id,
            $name,
            $slug,
            'Lekkie',
            $mainCategoryId,
            $additionalCategoryIds,
            'standard',
            [['key' => 'originals/'.str_repeat('a', 64).'.jpg', 'alt' => 'front']],
        );
    }
}
