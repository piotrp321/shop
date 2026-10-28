<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductCreated;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\TaxCategory;
use PHPUnit\Framework\TestCase;

final class ProductTest extends TestCase
{
    public function testCreateRecordsSingleProductCreatedEvent(): void
    {
        $before = new \DateTimeImmutable();
        $product = $this->product();
        $after = new \DateTimeImmutable();

        $events = $product->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(ProductCreated::class, $events[0]);
        self::assertSame('11111111-1111-4111-8111-111111111111', $events[0]->productId);
        self::assertGreaterThanOrEqual($before, $events[0]->occurredAt());
        self::assertLessThanOrEqual($after, $events[0]->occurredAt());
        self::assertGreaterThanOrEqual($before, $events[0]->occurredAt);
    }

    public function testEventsAreReleasedOnlyOnce(): void
    {
        $product = $this->product();
        $product->releaseEvents();

        self::assertSame([], $product->releaseEvents());
    }

    public function testCreateExposesGivenState(): void
    {
        $product = $this->product();

        self::assertSame('11111111-1111-4111-8111-111111111111', $product->id()->toString());
        self::assertSame('Shoes', $product->name()->value());
        self::assertSame('shoes', $product->slug()->value());
        self::assertSame('', $product->shortDescription()->value());
        self::assertSame(TaxCategory::Standard, $product->taxCategory());
        self::assertTrue($product->images()->isEmpty());
        self::assertSame('22222222-2222-4222-8222-222222222222', $product->categories()->main()->toString());
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductName('');
    }

    public function testNameOf200CharactersIsAccepted(): void
    {
        self::assertSame(200, mb_strlen((new ProductName(str_repeat('a', 200)))->value()));
    }

    public function testNameOf201CharactersIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProductName(str_repeat('a', 201));
    }

    public function testShortDescriptionOf501CharactersIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ShortDescription(str_repeat('a', 501));
    }

    public function testShortDescriptionOf500CharactersIsAccepted(): void
    {
        self::assertSame(500, mb_strlen((new ShortDescription(str_repeat('a', 500)))->value()));
    }

    public function testEmptyShortDescriptionIsAccepted(): void
    {
        self::assertSame('', (new ShortDescription(''))->value());
    }

    private function product(): Product
    {
        return Product::create(
            ProductId::fromString('11111111-1111-4111-8111-111111111111'),
            new ProductName('Shoes'),
            new Slug('shoes'),
            new ShortDescription(''),
            ProductCategories::create(CategoryId::fromString('22222222-2222-4222-8222-222222222222')),
            TaxCategory::Standard,
            ProductImages::fromList([]),
        );
    }
}
