<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\CreateProduct;
use App\Catalog\Application\Command\CreateProductHandler;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategoryNotFound;
use App\Catalog\Domain\Category\CategorySlug;
use App\Catalog\Domain\Product\DuplicateVariantValues;
use App\Catalog\Domain\Product\InvalidProductVariants;
use App\Catalog\Domain\Product\InvalidVariantAxes;
use App\Catalog\Domain\Product\ProductAlreadyExists;
use App\Catalog\Domain\Product\ProductCreated;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\SlugAlreadyTaken;
use App\Catalog\Domain\Product\SlugRequired;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Domain\Product\VariantAlreadyExists;
use App\Tests\Support\InMemory\FixedCatalogClock;
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
    private const string VARIANT_ID = '20000000-0000-4000-8000-000000000001';
    private const string OTHER_VARIANT_ID = '20000000-0000-4000-8000-000000000002';

    private InMemoryCategoryRepository $categories;
    private InMemoryProductRepository $products;
    private RecordingDomainEventPublisher $events;
    private FixedCatalogClock $clock;
    private CreateProductHandler $handler;

    protected function setUp(): void
    {
        $this->categories = new InMemoryCategoryRepository();
        $this->categories->save(Category::createRoot(CategoryId::fromString(self::MAIN_ID), new CategoryName('Sport'), new CategorySlug('sport')));
        $this->categories->save(Category::createRoot(CategoryId::fromString(self::EXTRA_ID), new CategoryName('Obuwie'), new CategorySlug('obuwie')));
        $this->products = new InMemoryProductRepository();
        $this->events = new RecordingDomainEventPublisher();
        $this->clock = new FixedCatalogClock();
        $this->handler = new CreateProductHandler($this->products, $this->categories, $this->events, $this->clock);
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
        self::assertSame(TaxClassCode::Standard, $product->taxClass());
        $images = $product->images()->toList();
        self::assertCount(1, $images);
        self::assertSame('originals/'.str_repeat('a', 64).'.jpg', $images[0]->key()->toString());
        self::assertSame('front', $images[0]->alt());
        self::assertSame(0, $images[0]->position());
        self::assertSame([], $product->variantAxes()->names());
        $variants = $product->variants()->toList();
        self::assertCount(1, $variants);
        self::assertSame(self::VARIANT_ID, $variants[0]->id()->toString());
        self::assertSame('', $variants[0]->label());

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

    public function testAxesAndVariantsAreSavedInTheGivenOrder(): void
    {
        ($this->handler)($this->command(
            variantAxes: ['Kolor', 'Rozmiar'],
            variants: [
                ['id' => self::VARIANT_ID, 'values' => ['czarny', ' 42 ']],
                ['id' => self::OTHER_VARIANT_ID, 'values' => ['biały', '42']],
            ],
        ));

        $product = $this->products->get(ProductId::fromString(self::PRODUCT_ID));
        self::assertSame(['Kolor', 'Rozmiar'], $product->variantAxes()->names());
        self::assertSame(
            [self::VARIANT_ID => 'czarny, 42', self::OTHER_VARIANT_ID => 'biały, 42'],
            array_column(array_map(static fn (ProductVariant $v): array => [$v->id()->toString(), $v->label()], $product->variants()->toList()), 1, 0),
        );
    }

    public function testRepeatedCombinationIsRefusedAndNothingIsSavedOrPublished(): void
    {
        try {
            ($this->handler)($this->command(
                variantAxes: ['Kolor'],
                variants: [
                    ['id' => self::VARIANT_ID, 'values' => ['Żółty']],
                    ['id' => self::OTHER_VARIANT_ID, 'values' => ['żółty']],
                ],
            ));
            self::fail('Expected DuplicateVariantValues.');
        } catch (DuplicateVariantValues) {
        }

        self::assertSame(0, $this->products->count());
        self::assertSame([], $this->events->published);
    }

    public function testVariantsThatBreakAnInvariantAreRefused(): void
    {
        try {
            ($this->handler)($this->command(variantAxes: ['Kolor'], variants: [['id' => self::VARIANT_ID, 'values' => []]]));
            self::fail('Expected InvalidProductVariants.');
        } catch (InvalidProductVariants) {
        }
        try {
            ($this->handler)($this->command(variants: []));
            self::fail('Expected InvalidProductVariants.');
        } catch (InvalidProductVariants) {
        }

        self::assertSame(0, $this->products->count());
    }

    public function testInvalidAxesAreRefused(): void
    {
        $this->expectException(InvalidVariantAxes::class);

        ($this->handler)($this->command(variantAxes: ['A', 'a']));
    }

    public function testVariantIdUsedByAnotherProductThrowsVariantAlreadyExistsAndPublishesNothing(): void
    {
        ($this->handler)($this->command());
        $this->events->published = [];

        try {
            ($this->handler)($this->command(id: '10000000-0000-4000-8000-000000000002', slug: 'inny-slug'));
            self::fail('Expected VariantAlreadyExists.');
        } catch (VariantAlreadyExists) {
        }

        self::assertSame(1, $this->products->count());
        self::assertSame([], $this->events->published);
    }

    public function testAProductOfThePanelIsADraftSavedWithItsActorAndTimeAndOnlyProductCreatedIsPublished(): void
    {
        ($this->handler)($this->command(status: ProductStatus::Draft, actorId: 'staff-sub-1'));

        $product = $this->products->get(ProductId::fromString(self::PRODUCT_ID));
        self::assertSame(ProductStatus::Draft, $product->status());
        self::assertNull($product->firstPublishedAt());
        self::assertSame(1, $product->version());
        self::assertEquals(['actorId' => 'staff-sub-1', 'at' => $this->clock->now], $this->products->lastWrites[self::PRODUCT_ID]);
        self::assertEquals([new ProductCreated(self::PRODUCT_ID, $this->clock->now)], $this->events->published);
    }

    public function testAProductOfTheSeedIsPublishedAtTheTimeOfCreationWithoutAnActor(): void
    {
        ($this->handler)($this->command(status: ProductStatus::Published, actorId: null));

        $product = $this->products->get(ProductId::fromString(self::PRODUCT_ID));
        self::assertSame(ProductStatus::Published, $product->status());
        self::assertEquals($this->clock->now, $product->firstPublishedAt());
        self::assertNull($this->products->lastWrites[self::PRODUCT_ID]['actorId']);
        self::assertCount(1, $this->events->published);
        self::assertInstanceOf(ProductCreated::class, $this->events->published[0]);
    }

    public function testAnEmptySlugIsDerivedFromTheName(): void
    {
        ($this->handler)($this->command(name: 'Plecak Śnieżka 30 L', slug: ''));

        self::assertSame('plecak-sniezka-30-l', $this->products->get(ProductId::fromString(self::PRODUCT_ID))->slug()->value());
    }

    public function testAnEmptySlugOfANameWithoutLettersOrDigitsIsSlugRequiredAndSavesNothing(): void
    {
        try {
            ($this->handler)($this->command(name: '— ? —', slug: ''));
            self::fail('Expected SlugRequired.');
        } catch (SlugRequired) {
        }

        self::assertSame(0, $this->products->count());
        self::assertSame([], $this->events->published);
    }

    /**
     * @param list<string>                                       $additionalCategoryIds
     * @param list<string>                                       $variantAxes
     * @param list<array{id: string, values: list<string>}>|null $variants              null: the default variant
     */
    private function command(
        string $id = self::PRODUCT_ID,
        string $name = 'Buty biegowe',
        string $slug = 'buty-biegowe',
        string $mainCategoryId = self::MAIN_ID,
        array $additionalCategoryIds = [self::EXTRA_ID],
        array $variantAxes = [],
        ?array $variants = null,
        ProductStatus $status = ProductStatus::Draft,
        ?string $actorId = 'staff-sub-1',
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
            $variantAxes,
            $variants ?? [['id' => self::VARIANT_ID, 'values' => []]],
            $status,
            $actorId,
        );
    }
}
