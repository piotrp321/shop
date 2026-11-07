<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductChanged;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductNotPublishable;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\SlugLocked;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantIdentityLocked;
use App\Catalog\Domain\Product\VariantInUse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Product::edit: the image rule of a published product (K2), the slug lock (K4), the identity of variants after the
 * first publication and the removal of a variant in use (K5).
 */
final class ProductEditTest extends TestCase
{
    private const string NOW = '2026-11-07T09:30:00.123456+00:00';
    private const string BLACK_M = '0192f0c4-0000-7000-8000-000000000001';
    private const string BLACK_XL = '0192f0c4-0000-7000-8000-000000000002';
    private const string NEW_VARIANT = '0192f0c4-0000-7000-8000-000000000003';
    private const string OTHER_CATEGORY = '44444444-4444-4444-8444-444444444444';

    private const array AXES = ['Kolor', 'Rozmiar'];
    private const array VARIANTS = [
        ['id' => self::BLACK_M, 'values' => ['czarne', 'M']],
        ['id' => self::BLACK_XL, 'values' => ['czarne', 'XL']],
    ];

    public function testEditReplacesTheDataAndRecordsProductChangedWithTheNewSlug(): void
    {
        $product = StoredProducts::product();

        $this->edit($product, name: 'Buty trailowe', slug: 'buty-trailowe', description: 'Na szlak', imageAlts: ['bok', 'przód'], taxClass: TaxClassCode::EnergyBar);

        self::assertSame('Buty trailowe', $product->name()->value());
        self::assertSame('buty-trailowe', $product->slug()->value());
        self::assertSame('Na szlak', $product->shortDescription()->value());
        self::assertSame(self::OTHER_CATEGORY, $product->categories()->main()->toString());
        self::assertSame(TaxClassCode::EnergyBar, $product->taxClass());
        self::assertSame(['bok', 'przód'], array_map(static fn ($image): string => $image->alt(), $product->images()->toList()));
        self::assertSame(ProductStatus::Draft, $product->status());
        $events = $product->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(ProductChanged::class, $events[0]);
        self::assertSame(StoredProducts::PRODUCT_ID, $events[0]->productId);
        self::assertSame('buty-trailowe', $events[0]->slug);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $events[0]->occurredAt());
    }

    public function testAnUnchangedEditStillRecordsProductChanged(): void
    {
        $product = StoredProducts::published();

        $this->edit($product);

        self::assertCount(1, $product->releaseEvents());
    }

    public function testEditOfAPublishedProductRemovingItsLastImageIsNotPublishableAndChangesNothing(): void
    {
        $product = StoredProducts::published(imageAlts: ['front', 'back']);

        try {
            $this->edit($product, name: 'Inna nazwa', imageAlts: []);
            self::fail('Expected ProductNotPublishable.');
        } catch (ProductNotPublishable $e) {
            self::assertSame('Opublikowany produkt musi mieć co najmniej jedno zdjęcie.', $e->getMessage());
        }

        self::assertSame('Buty biegowe', $product->name()->value());
        self::assertCount(2, $product->images()->toList());
        self::assertSame([], $product->releaseEvents());
    }

    public function testEditOfAPublishedProductWithoutImagesSavesWithoutAnImage(): void
    {
        $product = StoredProducts::published(imageAlts: []);

        $this->edit($product, name: 'Nowa nazwa', imageAlts: []);

        self::assertSame('Nowa nazwa', $product->name()->value());
        self::assertTrue($product->images()->isEmpty());
        self::assertCount(1, $product->releaseEvents());
    }

    public function testADraftCanLoseAllItsImages(): void
    {
        foreach ([StoredProducts::product(), StoredProducts::unpublished()] as $product) {
            $this->edit($product, imageAlts: []);

            self::assertTrue($product->images()->isEmpty());
        }
    }

    public function testSlugOfANeverPublishedProductCanChange(): void
    {
        $product = StoredProducts::product();

        $this->edit($product, slug: 'nowy-slug');

        self::assertSame('nowy-slug', $product->slug()->value());
    }

    public function testSlugIsLockedAfterTheFirstPublicationAlsoAfterUnpublication(): void
    {
        foreach ([StoredProducts::published(), StoredProducts::unpublished()] as $product) {
            try {
                $this->edit($product, name: 'Inna nazwa', slug: 'nowy-slug');
                self::fail('Expected SlugLocked.');
            } catch (SlugLocked $e) {
                self::assertSame('Slug produktu nie może się zmienić po pierwszej publikacji.', $e->getMessage());
            }

            self::assertSame(StoredProducts::SLUG, $product->slug()->value());
            self::assertSame('Buty biegowe', $product->name()->value());
            self::assertSame([], $product->releaseEvents());
        }
    }

    public function testTheSameSlugIsAcceptedAfterThePublication(): void
    {
        $product = StoredProducts::published();

        $this->edit($product, slug: StoredProducts::SLUG, name: 'Inna nazwa');

        self::assertSame('Inna nazwa', $product->name()->value());
    }

    public function testAfterThePublicationAChangeOfCaseOrOuterSpacesOfAValueKeepsTheVariant(): void
    {
        $product = StoredProducts::published(axes: self::AXES, variants: self::VARIANTS);

        $this->edit($product, axes: ['kolor', 'ROZMIAR'], variants: [
            ['id' => self::BLACK_M, 'values' => ['Czarne', 'm']],
            ['id' => self::BLACK_XL, 'values' => [' czarne ', 'XL']],
        ]);

        self::assertSame(['kolor', 'ROZMIAR'], $product->variantAxes()->names());
        self::assertSame(
            [self::BLACK_M => 'Czarne, m', self::BLACK_XL => 'czarne, XL'],
            $this->labels($product),
        );
    }

    public function testAfterThePublicationAChangedValueOfAKeptVariantIsLocked(): void
    {
        foreach ([StoredProducts::published(axes: self::AXES, variants: self::VARIANTS), StoredProducts::unpublished(axes: self::AXES, variants: self::VARIANTS)] as $product) {
            try {
                $this->edit($product, axes: self::AXES, variants: [
                    ['id' => self::BLACK_M, 'values' => ['czarne', 'L']],
                    ['id' => self::BLACK_XL, 'values' => ['czarne', 'XL']],
                ]);
                self::fail('Expected VariantIdentityLocked.');
            } catch (VariantIdentityLocked $e) {
                self::assertSame(
                    'Wariant „czarne, M” nie może zmienić wartości po pierwszej publikacji. Popraw tylko wielkość liter albo dodaj nowy wariant i usuń stary.',
                    $e->getMessage(),
                );
            }

            self::assertSame([self::BLACK_M => 'czarne, M', self::BLACK_XL => 'czarne, XL'], $this->labels($product));
            self::assertSame([], $product->releaseEvents());
        }
    }

    public function testAfterThePublicationMToXlOnTheSameVariantIsLockedEvenWhenTheOldOneIsRemoved(): void
    {
        $product = StoredProducts::published(axes: self::AXES, variants: self::VARIANTS);

        $this->expectException(VariantIdentityLocked::class);

        $this->edit($product, axes: self::AXES, variants: [['id' => self::BLACK_M, 'values' => ['czarne', 'XL']]]);
    }

    /**
     * @return iterable<string, array{list<string>, list<array{id: string, values: list<string>}>, string}>
     */
    public static function axisChanges(): iterable
    {
        $axesMessage = 'Po pierwszej publikacji nie można dodać, usunąć ani przestawić osi wariantów.';

        yield 'a removed axis' => [['Kolor'], [['id' => self::NEW_VARIANT, 'values' => ['czarne']]], $axesMessage];
        yield 'reordered axes' => [
            ['Rozmiar', 'Kolor'],
            [['id' => self::BLACK_M, 'values' => ['M', 'czarne']], ['id' => self::BLACK_XL, 'values' => ['XL', 'czarne']]],
            $axesMessage,
        ];
        yield 'a renamed axis' => [
            ['Barwa', 'Rozmiar'],
            self::VARIANTS,
            'Oś „Kolor” nie może zmienić nazwy po pierwszej publikacji. Popraw tylko wielkość liter.',
        ];
    }

    /**
     * @param list<string>                                  $axes
     * @param list<array{id: string, values: list<string>}> $variants
     */
    #[DataProvider('axisChanges')]
    public function testAfterThePublicationAxesCannotBeAddedRemovedReorderedOrRenamed(array $axes, array $variants, string $message): void
    {
        $product = StoredProducts::published(axes: self::AXES, variants: self::VARIANTS);

        try {
            $this->edit($product, axes: $axes, variants: $variants);
            self::fail('Expected VariantIdentityLocked.');
        } catch (VariantIdentityLocked $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertSame(self::AXES, $product->variantAxes()->names());
        self::assertSame([], $product->releaseEvents());
    }

    public function testAfterThePublicationAnAxisCannotBeAdded(): void
    {
        $product = StoredProducts::published(axes: ['Kolor'], variants: [['id' => self::BLACK_M, 'values' => ['czarne']]]);

        try {
            $this->edit($product, axes: self::AXES, variants: [self::VARIANTS[0]]);
            self::fail('Expected VariantIdentityLocked.');
        } catch (VariantIdentityLocked $e) {
            self::assertSame('Po pierwszej publikacji nie można dodać, usunąć ani przestawić osi wariantów.', $e->getMessage());
        }

        self::assertSame(['Kolor'], $product->variantAxes()->names());
    }

    public function testAfterThePublicationANewVariantIsAccepted(): void
    {
        $product = StoredProducts::published(axes: self::AXES, variants: self::VARIANTS);

        $this->edit($product, axes: self::AXES, variants: [...self::VARIANTS, ['id' => self::NEW_VARIANT, 'values' => ['białe', 'M']]]);

        self::assertSame([self::BLACK_M => 'czarne, M', self::BLACK_XL => 'czarne, XL', self::NEW_VARIANT => 'białe, M'], $this->labels($product));
    }

    public function testANeverPublishedDraftChangesValuesAndAxesFreely(): void
    {
        $product = StoredProducts::product(axes: self::AXES, variants: self::VARIANTS);

        $this->edit($product, axes: ['Długość', 'Kolor'], variants: [
            ['id' => self::BLACK_XL, 'values' => ['krótkie', 'zielone']],
            ['id' => self::BLACK_M, 'values' => ['długie', 'zielone']],
        ]);

        self::assertSame(['Długość', 'Kolor'], $product->variantAxes()->names());
        self::assertSame([self::BLACK_XL => 'krótkie, zielone', self::BLACK_M => 'długie, zielone'], $this->labels($product));

        // An axis added to a product without axes keeps the id of the default variant.
        $default = StoredProducts::product();
        $this->edit($default, axes: ['Kolor'], variants: [['id' => '33333333-3333-4333-8333-333333333333', 'values' => ['czarne']], ['id' => self::NEW_VARIANT, 'values' => ['białe']]]);
        self::assertSame(['33333333-3333-4333-8333-333333333333' => 'czarne', self::NEW_VARIANT => 'białe'], $this->labels($default));
    }

    public function testRemovalOfAVariantInUseIsRefusedWithItsLabel(): void
    {
        // Also before the first publication: the stock stays in Inventory.
        foreach ([StoredProducts::product(axes: self::AXES, variants: self::VARIANTS), StoredProducts::published(axes: self::AXES, variants: self::VARIANTS)] as $product) {
            try {
                $this->edit($product, axes: self::AXES, variants: [self::VARIANTS[0]], inUse: [self::BLACK_M, self::BLACK_XL]);
                self::fail('Expected VariantInUse.');
            } catch (VariantInUse $e) {
                self::assertSame('Wariant „czarne, XL” ma stan magazynowy albo rezerwacje. Najpierw skoryguj stan do zera.', $e->getMessage());
            }

            self::assertCount(2, $product->variants()->toList());
            self::assertSame([], $product->releaseEvents());
        }
    }

    public function testRemovalOfTheDefaultVariantInUseNamesTheDefaultVariant(): void
    {
        $product = StoredProducts::product();

        try {
            $this->edit($product, axes: ['Kolor'], variants: [['id' => self::NEW_VARIANT, 'values' => ['czarne']]], inUse: ['33333333-3333-4333-8333-333333333333']);
            self::fail('Expected VariantInUse.');
        } catch (VariantInUse $e) {
            self::assertSame('Wariant domyślny ma stan magazynowy albo rezerwacje. Najpierw skoryguj stan do zera.', $e->getMessage());
        }
    }

    public function testRemovalOfAVariantWithoutStockIsAcceptedAndKeptVariantsInUseDoNotMatter(): void
    {
        $product = StoredProducts::published(axes: self::AXES, variants: self::VARIANTS);

        $this->edit($product, axes: self::AXES, variants: [self::VARIANTS[0]], inUse: [self::BLACK_M]);

        self::assertSame([self::BLACK_M => 'czarne, M'], $this->labels($product));
    }

    /**
     * @param list<string>                                       $imageAlts
     * @param list<string>                                       $axes
     * @param list<array{id: string, values: list<string>}>|null $variants  null: the variants of the product
     * @param list<string>                                       $inUse
     */
    private function edit(
        Product $product,
        string $name = 'Buty biegowe',
        string $slug = StoredProducts::SLUG,
        string $description = '',
        array $imageAlts = ['front'],
        TaxClassCode $taxClass = TaxClassCode::Standard,
        ?array $axes = null,
        ?array $variants = null,
        array $inUse = [],
    ): void {
        $product->edit(
            new ProductName($name),
            new Slug($slug),
            new ShortDescription($description),
            ProductCategories::create(CategoryId::fromString(self::OTHER_CATEGORY)),
            $taxClass,
            StoredProducts::images($imageAlts),
            null === $variants ? $product->variants() : StoredProducts::variants($axes ?? [], $variants),
            array_map(VariantId::fromString(...), $inUse),
            new \DateTimeImmutable(self::NOW),
        );
    }

    /**
     * @return array<string, string>
     */
    private function labels(Product $product): array
    {
        $labels = [];
        foreach ($product->variants()->toList() as $variant) {
            $labels[$variant->id()->toString()] = $variant->label();
        }

        return $labels;
    }
}
