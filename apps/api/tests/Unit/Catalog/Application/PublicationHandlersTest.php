<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Command\PublishProduct;
use App\Catalog\Application\Command\PublishProductHandler;
use App\Catalog\Application\Command\UnpublishProduct;
use App\Catalog\Application\Command\UnpublishProductHandler;
use App\Catalog\Application\Event\ProductChanged;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductModifiedConcurrently;
use App\Catalog\Domain\Product\ProductNotFound;
use App\Catalog\Domain\Product\ProductNotPublishable;
use App\Catalog\Domain\Product\ProductStatus;
use App\Tests\Support\InMemory\FixedCatalogClock;
use App\Tests\Support\InMemory\InMemoryProductRepository;
use App\Tests\Support\InMemory\RecordingDomainEventPublisher;
use App\Tests\Unit\Catalog\Domain\StoredProducts;
use PHPUnit\Framework\TestCase;

/**
 * PublishProduct and UnpublishProduct: the version from the view first, then the rule of the aggregate; a request that
 * changes nothing saves and publishes nothing.
 */
final class PublicationHandlersTest extends TestCase
{
    private const string ACTOR = 'staff-sub-1';

    private InMemoryProductRepository $products;
    private RecordingDomainEventPublisher $events;
    private FixedCatalogClock $clock;
    private PublishProductHandler $publish;
    private UnpublishProductHandler $unpublish;

    protected function setUp(): void
    {
        $this->products = new InMemoryProductRepository();
        $this->events = new RecordingDomainEventPublisher();
        $this->clock = new FixedCatalogClock();
        $this->publish = new PublishProductHandler($this->products, $this->events, $this->clock);
        $this->unpublish = new UnpublishProductHandler($this->products, $this->events, $this->clock);
    }

    public function testPublicationOfADraftIsSavedWithANewVersionAndPublishesProductChanged(): void
    {
        $this->store(StoredProducts::product());

        ($this->publish)(new PublishProduct(StoredProducts::PRODUCT_ID, 1, self::ACTOR));

        $product = $this->stored();
        self::assertSame(ProductStatus::Published, $product->status());
        self::assertEquals($this->clock->now, $product->firstPublishedAt());
        self::assertSame(2, $product->version());
        self::assertEquals(['actorId' => self::ACTOR, 'at' => $this->clock->now], $this->products->lastWrites[StoredProducts::PRODUCT_ID]);
        self::assertEquals([new ProductChanged(StoredProducts::PRODUCT_ID, StoredProducts::SLUG, $this->clock->now)], $this->events->published);
    }

    public function testPublicationOfAPublishedProductSavesAndPublishesNothing(): void
    {
        $this->store(StoredProducts::published());

        ($this->publish)(new PublishProduct(StoredProducts::PRODUCT_ID, 1, self::ACTOR));

        self::assertSame(1, $this->stored()->version());
        self::assertSame([], $this->events->published);
    }

    public function testPublicationOfADraftWithoutImagesIsNotPublishableAndSavesNothing(): void
    {
        $this->store(StoredProducts::product(imageAlts: []));

        try {
            ($this->publish)(new PublishProduct(StoredProducts::PRODUCT_ID, 1, self::ACTOR));
            self::fail('Expected ProductNotPublishable.');
        } catch (ProductNotPublishable) {
        }

        self::assertSame(ProductStatus::Draft, $this->stored()->status());
        self::assertSame(1, $this->stored()->version());
        self::assertSame([], $this->events->published);
    }

    public function testUnpublicationOfAPublishedProductIsSavedWithANewVersionAndPublishesProductChanged(): void
    {
        $this->store(StoredProducts::published());

        ($this->unpublish)(new UnpublishProduct(StoredProducts::PRODUCT_ID, 1, self::ACTOR));

        $product = $this->stored();
        self::assertSame(ProductStatus::Draft, $product->status());
        self::assertSame(2, $product->version());
        self::assertEquals([new ProductChanged(StoredProducts::PRODUCT_ID, StoredProducts::SLUG, $this->clock->now)], $this->events->published);
    }

    public function testUnpublicationOfADraftSavesAndPublishesNothing(): void
    {
        $this->store(StoredProducts::product());

        ($this->unpublish)(new UnpublishProduct(StoredProducts::PRODUCT_ID, 1, self::ACTOR));

        self::assertSame(1, $this->stored()->version());
        self::assertSame([], $this->events->published);
    }

    public function testAStaleVersionIsProductModifiedAlsoWhenTheActionWouldChangeNothing(): void
    {
        $this->store(StoredProducts::product(imageAlts: []));
        $cases = [
            'publish of a draft without images' => fn () => ($this->publish)(new PublishProduct(StoredProducts::PRODUCT_ID, 2, self::ACTOR)),
            'unpublish of a draft' => fn () => ($this->unpublish)(new UnpublishProduct(StoredProducts::PRODUCT_ID, 0, self::ACTOR)),
        ];

        foreach ($cases as $case => $action) {
            try {
                $action();
                self::fail('Expected ProductModifiedConcurrently with '.$case.'.');
            } catch (ProductModifiedConcurrently) {
            }
        }

        self::assertSame(1, $this->stored()->version());
        self::assertSame([], $this->events->published);
    }

    public function testAnUnknownProductIsProductNotFound(): void
    {
        $this->expectException(ProductNotFound::class);

        ($this->unpublish)(new UnpublishProduct(StoredProducts::PRODUCT_ID, 1, self::ACTOR));
    }

    private function store(Product $product): void
    {
        $this->products->save($product, null, null, new \DateTimeImmutable('2026-11-01T10:00:00+00:00'));
    }

    private function stored(): Product
    {
        return $this->products->get(ProductId::fromString(StoredProducts::PRODUCT_ID));
    }
}
