<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The _source of a product document and the mapping of the fields of the facet model (spec search, M1, "Dokument"),
 * without Elasticsearch.
 */
final class ProductIndexSourceTest extends TestCase
{
    private const string ID = '0198a1f0-0000-7000-8000-000000000001';
    private const string US = "\u{1F}";

    public function testTheSourceCarriesTheFieldsOfTheFacetModel(): void
    {
        $source = ProductIndexDefinition::source(self::document());

        self::assertEqualsCanonicalizing([
            '#p', '#c', '@Kolor', '@Rozmiar', '$',
            'Kolor'.self::US.'czarne', 'Rozmiar'.self::US.'42', 'Kolor'.self::US.'Białe', 'Rozmiar'.self::US.'43',
        ], $source['facets']);
        self::assertEqualsCanonicalizing([
            '#p', '#c', '@Kolor', '@Rozmiar', 'Kolor'.self::US.'Białe', 'Rozmiar'.self::US.'43',
        ], $source['buy_facets']);
        self::assertCount(8, self::list($source, 'pair_any'));
        self::assertCount(4, self::list($source, 'pair_buy'));
        self::assertTrue($source['purchasable']);
        self::assertSame([19_900], $source['buy_prices']);
        self::assertSame(19_900, $source['max_gross']);
        self::assertSame(19_900, $source['from_gross']);
        self::assertSame(['Kolor', 'Rozmiar'], self::at($source, 'variants', 0, 'axes'));
        self::assertSame(['Kolor', 'Rozmiar'], self::at($source, 'variants', 1, 'axes'));
    }

    public function testTheVariantsKeepTheirOptionsWithoutNestedPairs(): void
    {
        $source = ProductIndexDefinition::source(self::document());

        self::assertSame(['Kolor'.self::US.'czarne', 'Rozmiar'.self::US.'42'], self::at($source, 'variants', 0, 'options'));
        // The pairs of a product are its tokens (pair_any, pair_buy); a variant has no nested pairs.
        $variant = self::at($source, 'variants', 0);
        self::assertIsArray($variant);
        self::assertSame(['gross', 'purchasable', 'options', 'axes'], array_keys($variant));
        self::assertSame(['p', 'c'], $source['category_ancestor_ids']);
        self::assertSame('p/c', $source['sort_path']);
    }

    public function testAProductWithoutAPurchasableVariantHasNoMaxGrossAndNoDollar(): void
    {
        $source = ProductIndexDefinition::source(self::document([new VariantDocument(null, false, ['Kolor'.self::US.'czarne'])], fromGross: null));

        self::assertArrayNotHasKey('max_gross', $source);
        self::assertArrayNotHasKey('from_gross', $source);
        self::assertFalse($source['purchasable']);
        self::assertSame([], $source['buy_prices']);
        self::assertSame([], $source['buy_facets']);
        self::assertSame([], $source['pair_buy']);
        self::assertNotContains('$', self::list($source, 'facets'));
    }

    public function testADraftHasTheTokensOfItsVariantsAndNoPrices(): void
    {
        $source = ProductIndexDefinition::source(self::document([new VariantDocument(null, false, ['Kolor'.self::US.'czarne'])], fromGross: null, status: ProductStatus::Draft));

        self::assertSame('draft', $source['status']);
        self::assertContains('@Kolor', self::list($source, 'facets'));
        self::assertFalse($source['purchasable']);
        self::assertSame([], $source['buy_prices']);
        self::assertArrayNotHasKey('max_gross', $source);
    }

    public function testAProductWithoutAxesHasEmptyOptionsAxesAndNoAxisTokens(): void
    {
        $source = ProductIndexDefinition::source(self::document([new VariantDocument(4_900, true, [])], fromGross: 4_900));

        self::assertSame([], self::at($source, 'variants', 0, 'options'));
        self::assertSame([], self::at($source, 'variants', 0, 'axes'));
        self::assertEqualsCanonicalizing(['#p', '#c', '$'], $source['facets']);
        self::assertSame([], $source['pair_any']);
    }

    public function testEveryFieldOfTheSourceIsMapped(): void
    {
        $mapping = new ProductIndexDefinition(0)->body()['mappings'];
        $properties = self::at($mapping, 'properties');
        $variantProperties = self::at($mapping, 'properties', 'variants', 'properties');
        $source = ProductIndexDefinition::source(self::document());
        $variant = self::at($source, 'variants', 0);
        \assert(\is_array($properties) && \is_array($variantProperties) && \is_array($variant));

        self::assertSame([], array_values(array_diff(array_keys($source), array_keys($properties))), 'a source field without a mapping is refused by dynamic: strict');
        self::assertSame([], array_values(array_diff(array_keys($variant), array_keys($variantProperties))));
    }

    public function testTheFacetFieldsAreMappedAsInTheSpec(): void
    {
        $mapping = new ProductIndexDefinition(0)->body()['mappings'];

        foreach (['facets', 'buy_facets', 'pair_any', 'pair_buy'] as $field) {
            self::assertSame(['type' => 'keyword'], self::at($mapping, 'properties', $field), $field);
        }
        self::assertSame(['type' => 'boolean'], self::at($mapping, 'properties', 'purchasable'));
        self::assertSame(['type' => 'long', 'doc_values' => false], self::at($mapping, 'properties', 'buy_prices'));
        self::assertSame(['type' => 'long'], self::at($mapping, 'properties', 'max_gross'));
        // Read only by the terms aggregation on its doc values, never searched.
        self::assertSame(['type' => 'keyword', 'index' => false], self::at($mapping, 'properties', 'variants', 'properties', 'axes'));
    }

    public function testTheFacetFieldsAreInTheDefinitionHash(): void
    {
        $withoutThem = new \ReflectionMethod(ProductIndexDefinition::class, 'mappings')->invoke(null);
        \assert(\is_array($withoutThem) && \is_array($withoutThem['properties']));
        foreach (['facets', 'buy_facets', 'pair_any', 'pair_buy', 'purchasable', 'buy_prices', 'max_gross'] as $field) {
            unset($withoutThem['properties'][$field]);
        }
        \assert(\is_array($withoutThem['properties']['variants']) && \is_array($withoutThem['properties']['variants']['properties']));
        unset($withoutThem['properties']['variants']['properties']['axes']);
        $analysis = new \ReflectionMethod(ProductIndexDefinition::class, 'analysis')->invoke(null);
        $sorting = new \ReflectionMethod(ProductIndexDefinition::class, 'sorting')->invoke(null);

        self::assertNotSame(hash('sha256', json_encode([$analysis, $withoutThem, $sorting], \JSON_THROW_ON_ERROR)), new ProductIndexDefinition(0)->hash());
    }

    /**
     * @return iterable<string, array{list<VariantDocument>}>
     */
    public static function variantsBreakingTheModel(): iterable
    {
        yield 'three options' => [[new VariantDocument(100, true, ['A'.self::US.'x', 'B'.self::US.'y', 'C'.self::US.'z'])]];
        yield 'a repeated tuple' => [[
            new VariantDocument(100, true, ['Kolor'.self::US.'czarne']),
            new VariantDocument(200, false, ['Kolor'.self::US.'czarne']),
        ]];
        yield 'the pair separator in a value' => [[new VariantDocument(100, true, ['Kolor'.self::US."cza\u{1E}rne"])]];
        yield 'the option separator in an axis' => [[new VariantDocument(100, true, ["Ko\u{1F}lor".self::US.'czarne'])]];
    }

    /**
     * @param list<VariantDocument> $variants
     */
    #[DataProvider('variantsBreakingTheModel')]
    public function testAProductBreakingARuleOfTheModelIsRefusedWithItsId(array $variants): void
    {
        try {
            ProductIndexDefinition::source(self::document($variants));
            self::fail('Expected SearchIndexError.');
        } catch (SearchIndexError $e) {
            self::assertStringContainsString(self::ID, $e->getMessage());
        }
    }

    public function testADocumentWithTheFacetFieldsIsReadBackWithoutThem(): void
    {
        $document = self::document();

        self::assertEquals($document, ProductIndexDefinition::document(ProductIndexDefinition::source($document)));
    }

    /**
     * @param list<VariantDocument>|null $variants
     */
    private static function document(?array $variants = null, ?int $fromGross = 19_900, ProductStatus $status = ProductStatus::Published): ProductDocument
    {
        return new ProductDocument(
            id: self::ID,
            version: 1_762_400_000_123_456,
            name: 'Kurtka puchowa',
            slug: 'kurtka-puchowa',
            shortDescription: 'Ciepła kurtka na zimę',
            mainCategoryId: 'c',
            mainCategoryPath: 'p/c',
            categoryIds: ['c'],
            categoryAncestorIds: ['p', 'c'],
            mainImage: null,
            categoryNames: ['Odzież', 'Kurtki'],
            variants: $variants ?? [
                new VariantDocument(18_900, false, ['Kolor'.self::US.'czarne', 'Rozmiar'.self::US.'42']),
                new VariantDocument(19_900, true, ['Kolor'.self::US.'Białe', 'Rozmiar'.self::US.'43']),
            ],
            fromGross: $fromGross,
            status: $status,
        );
    }

    /**
     * The value at the nested keys, null when any level is missing.
     */
    private static function at(mixed $data, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($data) || !\array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<mixed>
     */
    private static function list(array $source, string $field): array
    {
        $value = $source[$field] ?? null;
        \assert(\is_array($value));

        return $value;
    }
}
