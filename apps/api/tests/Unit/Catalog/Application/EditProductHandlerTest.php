<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\EditProduct;
use App\Catalog\Application\Command\EditProductHandler;
use App\Catalog\Application\Event\ProductChanged;
use App\Catalog\Application\Port\ProductImageStorageUnavailable;
use App\Catalog\Domain\Category\Category;
use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Category\CategoryName;
use App\Catalog\Domain\Category\CategorySlug;
use App\Catalog\Domain\Product\ImageNotFound;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\SlugLocked;
use App\Catalog\Domain\Product\SlugRequired;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Domain\Product\UnknownCategory;
use App\Catalog\Domain\Product\UnknownVariant;
use App\Catalog\Domain\Product\VariantInUse;
use App\Tests\Support\InMemory\FixedCatalogClock;
use App\Tests\Support\InMemory\InMemoryCategoryRepository;
use App\Tests\Support\InMemory\InMemoryProductImageStorage;
use App\Tests\Support\InMemory\InMemoryProductRepository;
use App\Tests\Support\InMemory\InMemoryVariantStockFacts;
use App\Tests\Support\InMemory\RecordingDomainEventPublisher;
use App\Tests\Unit\Catalog\Domain\StoredProducts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class EditProductHandlerTest extends TestCase
{
    private const string PRODUCT_ID = '10000000-0000-4000-8000-000000000001';
    private const string OTHER_PRODUCT_ID = '10000000-0000-4000-8000-000000000002';
    private const string CATEGORY_ID = '00000000-0000-4000-8000-000000000001';
    private const string BLACK = '20000000-0000-4000-8000-000000000001';
    private const string WHITE = '20000000-0000-4000-8000-000000000002';
    private const string FOREIGN = '20000000-0000-4000-8000-000000000009';
    private const string ACTOR = 'staff-sub-1';

    private InMemoryProductRepository $products;
    private InMemoryProductImageStorage $images;
    private InMemoryVariantStockFacts $stock;
    private RecordingDomainEventPublisher $events;
    private FixedCatalogClock $clock;
    private EditProductHandler $handler;
    private Category $category;

    protected function setUp(): void
    {
        $categories = new InMemoryCategoryRepository();
        $this->category = Category::createRoot(CategoryId::fromString(self::CATEGORY_ID), new CategoryName('Sport'), new CategorySlug('sport'));
        $categories->save($this->category);
        $this->products = new InMemoryProductRepository();
        $this->images = new InMemoryProductImageStorage();
        $this->images->existing = ['originals/'.hash('sha256', 'front').'.jpg'];
        $this->stock = new InMemoryVariantStockFacts();
        $this->events = new RecordingDomainEventPublisher();
        $this->clock = new FixedCatalogClock();
        $this->handler = new EditProductHandler($this->products, $categories, $this->stock, $this->images, $this->events, $this->clock);
    }

    public function testAnEditIsSavedWithANewVersionTheActorAndTheTimeAndPublishesTheIntegrationProductChanged(): void
    {
        $this->store(ProductStatus::Published);

        ($this->handler)($this->command(name: 'Buty trailowe', taxClass: 'energy_bar'));

        $product = $this->products->get(ProductId::fromString(self::PRODUCT_ID));
        self::assertSame('Buty trailowe', $product->name()->value());
        self::assertSame(TaxClassCode::EnergyBar, $product->taxClass());
        self::assertSame(2, $product->version());
        self::assertEquals(['actorId' => self::ACTOR, 'at' => $this->clock->now], $this->products->lastWrites[self::PRODUCT_ID]);
        self::assertEquals([new ProductChanged(self::PRODUCT_ID, 'buty-biegowe', $this->clock->now)], $this->events->published);
    }

    public function testAnUnchangedEditIsSavedAndPublishedAsWell(): void
    {
        $this->store(ProductStatus::Published);

        ($this->handler)($this->command());

        self::assertSame(2, $this->products->version(ProductId::fromString(self::PRODUCT_ID)));
        self::assertCount(1, $this->events->published);
    }

    public function testAnImageWithoutAnOriginalIsImageNotFoundAtItsPositionAndNothingIsSaved(): void
    {
        $this->store(ProductStatus::Published);
        $command = $this->command(images: [
            ['key' => 'originals/'.hash('sha256', 'front').'.jpg', 'alt' => 'front'],
            ['key' => 'originals/'.hash('sha256', 'gone').'.jpg', 'alt' => 'gone'],
        ]);

        try {
            ($this->handler)($command);
            self::fail('Expected ImageNotFound.');
        } catch (ImageNotFound $e) {
            self::assertSame(1, $e->index);
        }

        self::assertSame(1, $this->products->version(ProductId::fromString(self::PRODUCT_ID)));
        self::assertSame([], $this->events->published);
    }

    public function testAnUnavailableImageStorageIsNotAMissingImageAndNothingIsSaved(): void
    {
        $this->store(ProductStatus::Published);
        $this->images->unavailable = true;

        try {
            ($this->handler)($this->command(name: 'Inna nazwa'));
            self::fail('Expected ProductImageStorageUnavailable.');
        } catch (ProductImageStorageUnavailable) {
        }

        self::assertSame(1, $this->products->version(ProductId::fromString(self::PRODUCT_ID)));
        self::assertSame([], $this->events->published);
    }

    public function testUnknownCategoriesAreUnknownCategoryAtTheirPosition(): void
    {
        $this->store(ProductStatus::Published);
        $unknown = '00000000-0000-4000-8000-0000000000ff';
        $main = null;
        $additional = null;

        try {
            ($this->handler)($this->command(mainCategoryId: $unknown));
        } catch (UnknownCategory $e) {
            $main = $e->index;
        }
        try {
            ($this->handler)($this->command(additionalCategoryIds: [$unknown]));
        } catch (UnknownCategory $e) {
            $additional = $e->index;
        }

        self::assertNull($main);
        self::assertSame(0, $additional);
        self::assertSame(1, $this->products->version(ProductId::fromString(self::PRODUCT_ID)));
    }

    public function testAStaleVersionIsRefusedBeforeTheStorageIsAsked(): void
    {
        $this->store(ProductStatus::Published);
        $this->images->unavailable = true;

        $this->expectException(ProductModifiedConcurrently::class);

        ($this->handler)($this->command(expectedVersion: 7));
    }

    public function testAStaleVersionIsProductModifiedBeforeAnyOtherRule(): void
    {
        $this->store(ProductStatus::Published);
        $this->stock->inUse = [self::WHITE];
        $stale = [
            'a removed variant in use' => $this->command(expectedVersion: 7, variants: [['variantId' => self::BLACK, 'values' => ['czarne']]]),
            'a locked slug' => $this->command(expectedVersion: 7, slug: 'nowy-slug'),
            'a foreign variant' => $this->command(expectedVersion: 7, variants: [['variantId' => self::FOREIGN, 'values' => ['czarne']]]),
            'no images' => $this->command(expectedVersion: 7, images: []),
            'nothing else' => $this->command(expectedVersion: 7),
        ];

        foreach ($stale as $case => $command) {
            try {
                ($this->handler)($command);
                self::fail('Expected ProductModifiedConcurrently with '.$case.'.');
            } catch (ProductModifiedConcurrently) {
            }
        }

        self::assertSame([], $this->stock->asked, 'the stock is not read for a stale version');
        self::assertSame(1, $this->products->version(ProductId::fromString(self::PRODUCT_ID)));
        self::assertSame([], $this->events->published);
    }

    public function testRowsKeepTheirIdsAndRowsWithoutAnIdGetANewUuidV7(): void
    {
        $this->store(ProductStatus::Draft);

        ($this->handler)($this->command(variants: [
            ['variantId' => self::WHITE, 'values' => ['białe']],
            ['variantId' => null, 'values' => ['zielone']],
            ['variantId' => self::BLACK, 'values' => ['czarne']],
        ]));

        $variants = $this->products->get(ProductId::fromString(self::PRODUCT_ID))->variants()->toList();
        self::assertSame([self::WHITE, self::BLACK], [$variants[0]->id()->toString(), $variants[2]->id()->toString()]);
        self::assertSame(['białe', 'zielone', 'czarne'], array_map(static fn (ProductVariant $variant): string => $variant->label(), $variants));
        $new = $variants[1]->id()->toString();
        self::assertTrue(UuidV7::isValid($new) && '7' === $new[14], $new.' is a UUID v7');
        self::assertSame([], $this->stock->asked, 'no variant was removed');
    }

    public function testAVariantIdOfAnotherProductIsAnUnknownVariantAtItsRowAndNothingIsSaved(): void
    {
        $this->store(ProductStatus::Draft);
        $this->products->save($this->product(self::OTHER_PRODUCT_ID, 'inny', ProductStatus::Draft, [], [['id' => self::FOREIGN, 'values' => []]]), null, null, $this->clock->now);

        try {
            ($this->handler)($this->command(variants: [
                ['variantId' => self::BLACK, 'values' => ['czarne']],
                ['variantId' => self::FOREIGN, 'values' => ['zielone']],
            ]));
            self::fail('Expected UnknownVariant.');
        } catch (UnknownVariant $e) {
            self::assertSame(1, $e->index);
        }

        self::assertSame(1, $this->products->version(ProductId::fromString(self::PRODUCT_ID)));
        self::assertSame([], $this->events->published);
    }

    public function testOnlyTheRemovedVariantsAreAskedForStockAndOneInUseIsRefused(): void
    {
        $this->store(ProductStatus::Published);
        $this->stock->inUse = [self::BLACK, self::WHITE];

        try {
            ($this->handler)($this->command(variants: [['variantId' => self::BLACK, 'values' => ['czarne']]]));
            self::fail('Expected VariantInUse.');
        } catch (VariantInUse) {
        }

        self::assertSame([[self::WHITE]], $this->stock->asked);
        self::assertSame(1, $this->products->version(ProductId::fromString(self::PRODUCT_ID)));
        self::assertSame([], $this->events->published);
    }

    public function testARemovedVariantWithoutStockIsRemoved(): void
    {
        $this->store(ProductStatus::Published);

        ($this->handler)($this->command(variants: [['variantId' => self::BLACK, 'values' => ['czarne']]]));

        self::assertSame([self::BLACK], array_map(
            static fn (ProductVariant $variant): string => $variant->id()->toString(),
            $this->products->get(ProductId::fromString(self::PRODUCT_ID))->variants()->toList(),
        ));
    }

    public function testAnEmptySlugOfAProductNeverPublishedIsDerivedFromTheName(): void
    {
        $this->store(ProductStatus::Draft);

        ($this->handler)($this->command(name: 'Żółte Łapki 2', slug: ''));

        self::assertSame('zolte-lapki-2', $this->products->get(ProductId::fromString(self::PRODUCT_ID))->slug()->value());
        self::assertEquals([new ProductChanged(self::PRODUCT_ID, 'zolte-lapki-2', $this->clock->now)], $this->events->published);
    }

    public function testAnEmptySlugOfAPublishedProductKeepsItsSlug(): void
    {
        $this->store(ProductStatus::Published);

        ($this->handler)($this->command(name: 'Inna nazwa', slug: ''));

        self::assertSame('buty-biegowe', $this->products->get(ProductId::fromString(self::PRODUCT_ID))->slug()->value());
    }

    public function testANameWithoutLettersOrDigitsAndAnEmptySlugIsSlugRequired(): void
    {
        $this->store(ProductStatus::Draft);

        $this->expectException(SlugRequired::class);

        ($this->handler)($this->command(name: '!!!', slug: ''));
    }

    public function testAChangedSlugOfAPublishedProductIsLocked(): void
    {
        $this->store(ProductStatus::Published);

        $this->expectException(SlugLocked::class);

        ($this->handler)($this->command(slug: 'nowy-slug'));
    }

    public function testAnUnknownProductIsProductNotFound(): void
    {
        $this->expectException(ProductNotFound::class);

        ($this->handler)($this->command());
    }

    private function store(ProductStatus $status): void
    {
        $product = $this->product(self::PRODUCT_ID, 'buty-biegowe', $status, ['Kolor'], [['id' => self::BLACK, 'values' => ['czarne']], ['id' => self::WHITE, 'values' => ['białe']]]);
        $this->products->save($product, null, null, new \DateTimeImmutable('2026-11-01T10:00:00+00:00'));
    }

    /**
     * @param list<string>                                  $axes
     * @param list<array{id: string, values: list<string>}> $variants
     */
    private function product(string $id, string $slug, ProductStatus $status, array $axes, array $variants): Product
    {
        return Product::create(
            ProductId::fromString($id),
            new ProductName('Buty biegowe'),
            new Slug($slug),
            new ShortDescription(''),
            ProductCategories::create($this->category->id()),
            TaxClassCode::Standard,
            StoredProducts::images(['front']),
            StoredProducts::variants($axes, $variants),
            $status,
            new \DateTimeImmutable('2026-11-01T10:00:00+00:00'),
        );
    }

    /**
     * @param list<array{key: string, alt: string}>|null                     $images                null: the stored image
     * @param list<array{variantId: string|null, values: list<string>}>|null $variants              null: both stored variants
     * @param list<string>                                                   $additionalCategoryIds
     */
    private function command(
        int $expectedVersion = 1,
        string $name = 'Buty biegowe',
        string $slug = 'buty-biegowe',
        string $taxClass = 'standard',
        ?array $images = null,
        ?array $variants = null,
        string $mainCategoryId = self::CATEGORY_ID,
        array $additionalCategoryIds = [],
    ): EditProduct {
        return new EditProduct(
            self::PRODUCT_ID,
            $expectedVersion,
            self::ACTOR,
            $name,
            $slug,
            '',
            $mainCategoryId,
            $additionalCategoryIds,
            $taxClass,
            $images ?? [['key' => 'originals/'.hash('sha256', 'front').'.jpg', 'alt' => 'front']],
            ['Kolor'],
            $variants ?? [['variantId' => self::BLACK, 'values' => ['czarne']], ['variantId' => self::WHITE, 'values' => ['białe']]],
        );
    }
}
