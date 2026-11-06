<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Application\Search\ProductDocumentBuilder;
use App\Catalog\Application\Search\ProductFacts;
use App\Catalog\Application\Search\ProductSearchSnapshot;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Application\Search\VariantFacts;
use PHPUnit\Framework\TestCase;

final class ProductDocumentBuilderTest extends TestCase
{
    private const string ROOT = '00000000-0000-4000-8000-000000000001';
    private const string CHILD = '00000000-0000-4000-8000-000000000002';
    private const string LEAF = '00000000-0000-4000-8000-000000000003';
    private const string SIBLING = '00000000-0000-4000-8000-000000000004';
    private const string OTHER_ROOT = '00000000-0000-4000-8000-000000000005';

    private const string BLACK_42 = '10000000-0000-4000-8000-000000000001';
    private const string BLACK_43 = '10000000-0000-4000-8000-000000000002';
    private const string WHITE_42 = '10000000-0000-4000-8000-000000000003';

    public function testAncestorIdsAreTheUnionOfAllPathsWithoutDuplicates(): void
    {
        // Main category ROOT > CHILD > LEAF, additional ROOT > CHILD > SIBLING (same subtree) and OTHER_ROOT.
        $document = $this->build($this->snapshot(
            mainCategoryId: self::LEAF,
            additionalCategoryIds: [self::SIBLING, self::OTHER_ROOT],
            categoryPaths: [
                [self::ROOT, self::CHILD, self::LEAF],
                [self::ROOT, self::CHILD, self::SIBLING],
                [self::OTHER_ROOT],
            ],
        ));

        self::assertSame([self::ROOT, self::CHILD, self::LEAF, self::SIBLING, self::OTHER_ROOT], $document->categoryAncestorIds);
    }

    public function testDocumentCarriesNoTaxClass(): void
    {
        $document = $this->build($this->snapshot(
            mainCategoryId: self::ROOT,
            additionalCategoryIds: [],
            categoryPaths: [[self::ROOT]],
        ));

        $fields = array_keys(get_object_vars($document));
        self::assertSame([], array_filter($fields, static fn (string $field): bool => str_contains(strtolower($field), 'tax')));
    }

    public function testCategoryIdsStartWithTheMainCategory(): void
    {
        $document = $this->build($this->snapshot(
            mainCategoryId: self::LEAF,
            additionalCategoryIds: [self::ROOT, self::OTHER_ROOT],
            categoryPaths: [[self::ROOT, self::CHILD, self::LEAF], [self::ROOT], [self::OTHER_ROOT]],
        ));

        self::assertSame(self::LEAF, $document->mainCategoryId);
        self::assertSame([self::LEAF, self::ROOT, self::OTHER_ROOT], $document->categoryIds);
    }

    public function testCopiesTheFieldsFromTheSnapshotWithTheGivenVersion(): void
    {
        $document = new ProductDocumentBuilder()->build(
            $this->snapshot(mainImage: ['key' => 'originals/abc.jpg', 'alt' => 'Front']),
            null,
            1_762_400_000_123_456,
        );

        self::assertSame('0198a1f0-0000-7000-8000-000000000001', $document->id);
        self::assertSame(1_762_400_000_123_456, $document->version);
        self::assertSame('Żel do mycia', $document->name);
        self::assertSame('zel-do-mycia', $document->slug);
        self::assertSame('Short text', $document->shortDescription);
        self::assertSame(['key' => 'originals/abc.jpg', 'alt' => 'Front'], $document->mainImage);
    }

    public function testMainImageIsNullWithoutImages(): void
    {
        $document = $this->build($this->snapshot(mainImage: null));

        self::assertNull($document->mainImage);
    }

    public function testCategoryNamesAreTheNamesOfTheCategoriesAndTheirAncestors(): void
    {
        $document = $this->build($this->snapshot(categoryNames: ['Bieganie', 'Buty', 'Odzież']));

        self::assertSame(['Bieganie', 'Buty', 'Odzież'], $document->categoryNames);
    }

    public function testEachVariantHasTheProductAxesItsOptionsAndItsOfferFacts(): void
    {
        $document = $this->build($this->sizedSnapshot(), new ProductFacts(19_900, [
            self::BLACK_42 => new VariantFacts(19_900, true),
            self::BLACK_43 => new VariantFacts(21_900, false),
            self::WHITE_42 => new VariantFacts(null, false),
        ]));

        self::assertEquals([
            new VariantDocument(19_900, true, ['Kolor', 'Rozmiar'], ["Kolor\u{1F}czarne", "Rozmiar\u{1F}42"]),
            new VariantDocument(21_900, false, ['Kolor', 'Rozmiar'], ["Kolor\u{1F}czarne", "Rozmiar\u{1F}43"]),
            new VariantDocument(null, false, ['Kolor', 'Rozmiar'], ["Kolor\u{1F}Białe", "Rozmiar\u{1F}42"]),
        ], $document->variants);
        self::assertSame(19_900, $document->fromGross);
    }

    public function testTheFromPriceIsTheOneOfTheOfferNotComputedHere(): void
    {
        // The offer decides; the index never recomputes it from the variants.
        $document = $this->build($this->sizedSnapshot(), new ProductFacts(null, [
            self::BLACK_42 => new VariantFacts(19_900, false),
        ]));

        self::assertNull($document->fromGross);
    }

    public function testTheDefaultVariantHasNoAxesAndNoOptions(): void
    {
        $document = $this->build(
            $this->snapshot(variants: [['id' => self::BLACK_42, 'values' => []]]),
            new ProductFacts(4_900, [self::BLACK_42 => new VariantFacts(4_900, true)]),
        );

        self::assertEquals([new VariantDocument(4_900, true, [], [])], $document->variants);
        self::assertSame(4_900, $document->fromGross);
    }

    public function testAProductWithoutOfferFactsHasNoPricesAndNothingPurchasable(): void
    {
        $document = $this->build($this->sizedSnapshot(), null);

        self::assertCount(3, $document->variants);
        foreach ($document->variants as $variant) {
            self::assertNull($variant->gross);
            self::assertFalse($variant->purchasable);
        }
        self::assertNull($document->fromGross);
    }

    public function testAVariantMissingFromTheOfferFactsHasNoPriceAndIsNotPurchasable(): void
    {
        $document = $this->build($this->sizedSnapshot(), new ProductFacts(19_900, [
            self::BLACK_42 => new VariantFacts(19_900, true),
            // A variant the offer knows but the snapshot does not (e.g. read at another moment) is ignored.
            '10000000-0000-4000-8000-0000000000ff' => new VariantFacts(100, true),
        ]));

        self::assertCount(3, $document->variants);
        self::assertEquals(new VariantDocument(null, false, ['Kolor', 'Rozmiar'], ["Kolor\u{1F}czarne", "Rozmiar\u{1F}43"]), $document->variants[1]);
        self::assertEquals(new VariantDocument(null, false, ['Kolor', 'Rozmiar'], ["Kolor\u{1F}Białe", "Rozmiar\u{1F}42"]), $document->variants[2]);
    }

    private function build(ProductSearchSnapshot $snapshot, ?ProductFacts $facts = null): \App\Catalog\Application\Search\ProductDocument
    {
        return new ProductDocumentBuilder()->build($snapshot, $facts, 1);
    }

    private function sizedSnapshot(): ProductSearchSnapshot
    {
        return $this->snapshot(variantAxes: ['Kolor', 'Rozmiar'], variants: [
            ['id' => self::BLACK_42, 'values' => ['czarne', '42']],
            ['id' => self::BLACK_43, 'values' => ['czarne', '43']],
            ['id' => self::WHITE_42, 'values' => ['Białe', '42']],
        ]);
    }

    /**
     * @param list<string>                                  $additionalCategoryIds
     * @param list<list<string>>                            $categoryPaths
     * @param array{key: string, alt: string}|null          $mainImage
     * @param list<string>                                  $categoryNames
     * @param list<string>                                  $variantAxes
     * @param list<array{id: string, values: list<string>}> $variants
     */
    private function snapshot(
        string $mainCategoryId = self::ROOT,
        array $additionalCategoryIds = [],
        array $categoryPaths = [[self::ROOT]],
        ?array $mainImage = null,
        array $categoryNames = ['Kosmetyki'],
        array $variantAxes = [],
        array $variants = [['id' => self::BLACK_42, 'values' => []]],
    ): ProductSearchSnapshot {
        return new ProductSearchSnapshot(
            '0198a1f0-0000-7000-8000-000000000001',
            'Żel do mycia',
            'zel-do-mycia',
            'Short text',
            $mainCategoryId,
            $additionalCategoryIds,
            $categoryPaths,
            $mainImage,
            $categoryNames,
            $variantAxes,
            $variants,
        );
    }
}
