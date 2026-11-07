<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductChanged;
use App\Catalog\Domain\Product\ProductCreated;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductNotPublishable;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\TaxClassCode;
use PHPUnit\Framework\TestCase;

/**
 * Status of a product (K1) and the image rule of publication (K2).
 */
final class ProductPublicationTest extends TestCase
{
    private const string NOW = '2026-11-07T09:30:00.123456+00:00';

    public function testCreateAsDraftHasNoFirstPublication(): void
    {
        $product = $this->created(ProductStatus::Draft);

        self::assertSame(ProductStatus::Draft, $product->status());
        self::assertNull($product->firstPublishedAt());
        self::assertFalse($product->isSlugLocked());
        self::assertSame(0, $product->version());
    }

    public function testCreateAsPublishedIsPublishedAtTheTimeOfCreationAndRecordsOnlyProductCreated(): void
    {
        // The demo data has products without images: create does not check K2.
        $product = $this->created(ProductStatus::Published);

        self::assertSame(ProductStatus::Published, $product->status());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $product->firstPublishedAt());
        self::assertTrue($product->isSlugLocked());
        $events = $product->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ProductCreated::class, $events[0]);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $events[0]->occurredAt());
    }

    public function testReconstituteRestoresStatusFirstPublicationAndVersion(): void
    {
        $product = StoredProducts::unpublished();

        self::assertSame(ProductStatus::Draft, $product->status());
        self::assertEquals(new \DateTimeImmutable(StoredProducts::FIRST_PUBLISHED_AT), $product->firstPublishedAt());
        self::assertSame(3, $product->version());
        self::assertSame([], $product->releaseEvents());
    }

    public function testPublishOfADraftWithoutImagesIsNotPublishableAndChangesNothing(): void
    {
        $product = StoredProducts::product(imageAlts: []);

        try {
            $product->publish(new \DateTimeImmutable(self::NOW));
            self::fail('Expected ProductNotPublishable.');
        } catch (ProductNotPublishable $e) {
            self::assertSame('Dodaj co najmniej jedno zdjęcie przed publikacją.', $e->getMessage());
        }

        self::assertSame(ProductStatus::Draft, $product->status());
        self::assertNull($product->firstPublishedAt());
        self::assertSame([], $product->releaseEvents());
    }

    public function testPublishOfADraftSetsTheStatusAndTheFirstPublicationAndRecordsProductChanged(): void
    {
        $product = StoredProducts::product();

        $product->publish(new \DateTimeImmutable(self::NOW));

        self::assertSame(ProductStatus::Published, $product->status());
        self::assertEquals(new \DateTimeImmutable(self::NOW), $product->firstPublishedAt());
        self::assertTrue($product->isSlugLocked());
        $events = $product->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ProductChanged::class, $events[0]);
        self::assertSame(StoredProducts::PRODUCT_ID, $events[0]->productId);
        self::assertSame(StoredProducts::SLUG, $events[0]->slug);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $events[0]->occurredAt());
    }

    public function testRepublishKeepsTheFirstPublication(): void
    {
        $product = StoredProducts::unpublished();

        $product->publish(new \DateTimeImmutable(self::NOW));

        self::assertSame(ProductStatus::Published, $product->status());
        self::assertEquals(new \DateTimeImmutable(StoredProducts::FIRST_PUBLISHED_AT), $product->firstPublishedAt());
        self::assertCount(1, $product->releaseEvents());
    }

    public function testPublishOfAPublishedProductChangesNothingAndRecordsNoEvent(): void
    {
        // Also without images (a demo product): it is already published, so there is nothing to refuse.
        foreach ([StoredProducts::published(), StoredProducts::published(imageAlts: [])] as $product) {
            $product->publish(new \DateTimeImmutable(self::NOW));

            self::assertSame(ProductStatus::Published, $product->status());
            self::assertEquals(new \DateTimeImmutable(StoredProducts::FIRST_PUBLISHED_AT), $product->firstPublishedAt());
            self::assertSame([], $product->releaseEvents());
        }
    }

    public function testUnpublishOfAPublishedProductMakesItADraftKeepsTheFirstPublicationAndRecordsProductChanged(): void
    {
        $product = StoredProducts::published();

        $product->unpublish(new \DateTimeImmutable(self::NOW));

        self::assertSame(ProductStatus::Draft, $product->status());
        self::assertEquals(new \DateTimeImmutable(StoredProducts::FIRST_PUBLISHED_AT), $product->firstPublishedAt());
        self::assertTrue($product->isSlugLocked());
        $events = $product->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ProductChanged::class, $events[0]);
        self::assertSame(StoredProducts::SLUG, $events[0]->slug);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $events[0]->occurredAt());
    }

    public function testUnpublishOfADraftChangesNothingAndRecordsNoEvent(): void
    {
        foreach ([StoredProducts::product(), StoredProducts::unpublished()] as $product) {
            $firstPublishedAt = $product->firstPublishedAt();

            $product->unpublish(new \DateTimeImmutable(self::NOW));

            self::assertSame(ProductStatus::Draft, $product->status());
            self::assertEquals($firstPublishedAt, $product->firstPublishedAt());
            self::assertSame([], $product->releaseEvents());
        }
    }

    private function created(ProductStatus $status): Product
    {
        return Product::create(
            ProductId::fromString(StoredProducts::PRODUCT_ID),
            new ProductName('Buty biegowe'),
            new Slug(StoredProducts::SLUG),
            new ShortDescription(''),
            ProductCategories::create(CategoryId::fromString(StoredProducts::CATEGORY_ID)),
            TaxClassCode::Standard,
            StoredProducts::images([]),
            StoredProducts::variants([], [['id' => '33333333-3333-4333-8333-333333333333', 'values' => []]]),
            $status,
            new \DateTimeImmutable(self::NOW),
        );
    }
}
