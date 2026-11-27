<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Search;

use App\Catalog\Application\Search\IndexOutcome;
use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Infrastructure\Search\ElasticsearchProductIndexer;
use App\Catalog\Infrastructure\Search\ProductFacetTokens;
use App\Catalog\Infrastructure\Search\ProductIndexDefinition;
use Elastic\Elasticsearch\Response\Elasticsearch;
use PHPUnit\Framework\TestCase;

/**
 * The analysis and the mapping of a product index on the real Elasticsearch (with the analysis-stempel plugin).
 */
final class ProductIndexDefinitionTest extends TestCase
{
    use UsesTemporarySearchAlias;

    private const string ID = '0198a1f0-0000-7000-8000-000000000001';

    public function testTextFieldsAreStemmedInPolish(): void
    {
        $this->maintenance()->setup();

        foreach (['name', 'category_names', 'short_description'] as $field) {
            self::assertSame($this->tokens($field, 'kurtki'), $this->tokens($field, 'kurtka'), $field);
            self::assertSame($this->tokens($field, 'Kurtki'), $this->tokens($field, 'kurtkami'), $field);
        }
    }

    public function testFoldedSubfieldsDropPolishCharactersWithoutStemming(): void
    {
        $this->maintenance()->setup();

        foreach (['name.folded', 'category_names.folded', 'short_description.folded'] as $field) {
            self::assertSame(['spiwor', 'zolty'], $this->tokens($field, 'Śpiwór ŻÓŁTY'), $field);
            self::assertSame(['kurtki'], $this->tokens($field, 'kurtki'), $field);
        }
    }

    public function testANewIndexHasOneShardIsSortedByTheCategoryPathAndDoesNotIndexIt(): void
    {
        $this->maintenance()->setup();
        $index = $this->maintenance()->activeIndex();

        $settings = self::esClient()->indices()->getSettings(['index' => $index]);
        \assert($settings instanceof Elasticsearch);
        self::assertSame('1', self::at($settings->asArray(), $index, 'settings', 'index', 'number_of_shards'));
        self::assertSame('sort_path', self::at($settings->asArray(), $index, 'settings', 'index', 'sort', 'field'));
        self::assertSame('asc', self::at($settings->asArray(), $index, 'settings', 'index', 'sort', 'order'));

        $mapping = self::esClient()->indices()->getMapping(['index' => $index]);
        \assert($mapping instanceof Elasticsearch);
        self::assertSame(['type' => 'keyword', 'index' => false], self::at($mapping->asArray(), $index, 'mappings', 'properties', 'sort_path'));
    }

    public function testThePathOfTheMainCategoryIsStoredAsTheSortKeyAndReadBack(): void
    {
        $this->maintenance()->setup();
        $document = self::document();

        self::assertSame('p/c', ProductIndexDefinition::source($document)['sort_path']);
        $this->indexer()->index($document);

        $stored = self::esClient()->get(['index' => $this->alias(), 'id' => self::ID]);
        \assert($stored instanceof Elasticsearch);
        $source = $stored['_source'];
        \assert(\is_array($source));
        self::assertSame('p/c', $source['sort_path']);
        self::assertEquals($document, ProductIndexDefinition::document($source));
    }

    public function testADocumentWithVariantsIsStoredAndReadBack(): void
    {
        $this->maintenance()->setup();
        $document = self::document();

        self::assertSame(IndexOutcome::Indexed, $this->indexer()->index($document));

        $stored = self::esClient()->get(['index' => $this->alias(), 'id' => self::ID]);
        \assert($stored instanceof Elasticsearch);
        $source = $stored['_source'];
        self::assertIsArray($source);
        self::assertSame(['Odzież', 'Kurtki'], $source['category_names']);
        self::assertSame(19_900, $source['from_gross']);
        self::assertSame([
            ['gross' => 18_900, 'purchasable' => false, 'options' => ["Kolor\u{1F}czarne", "Rozmiar\u{1F}42"], 'axes' => ['Kolor', 'Rozmiar'], 'pairs' => [['axis' => 'Kolor', 'value' => 'czarne'], ['axis' => 'Rozmiar', 'value' => '42']]],
            ['gross' => 19_900, 'purchasable' => true, 'options' => ["Kolor\u{1F}Białe", "Rozmiar\u{1F}43"], 'axes' => ['Kolor', 'Rozmiar'], 'pairs' => [['axis' => 'Kolor', 'value' => 'Białe'], ['axis' => 'Rozmiar', 'value' => '43']]],
            ['purchasable' => false, 'options' => ["Kolor\u{1F}zielone", "Rozmiar\u{1F}44"], 'axes' => ['Kolor', 'Rozmiar'], 'pairs' => [['axis' => 'Kolor', 'value' => 'zielone'], ['axis' => 'Rozmiar', 'value' => '44']]],
        ], $source['variants']);
        self::assertEquals($document, ProductIndexDefinition::document($source));
    }

    public function testADraftIsStoredWithItsStatusAndReadBack(): void
    {
        $this->maintenance()->setup();
        $document = self::document(status: ProductStatus::Draft);

        self::assertSame(IndexOutcome::Indexed, $this->indexer()->index($document));

        $stored = self::esClient()->get(['index' => $this->alias(), 'id' => self::ID]);
        \assert($stored instanceof Elasticsearch);
        $source = $stored['_source'];
        self::assertIsArray($source);
        self::assertSame('draft', $source['status']);
        self::assertEquals($document, ProductIndexDefinition::document($source));
        self::assertSame('published', ProductIndexDefinition::source(self::document())['status']);
    }

    public function testASourceWithoutAKnownStatusDoesNotMatchTheDefinition(): void
    {
        $source = ProductIndexDefinition::source(self::document());

        foreach ([null, 'archived', 1] as $status) {
            try {
                ProductIndexDefinition::document([...$source, 'status' => $status]);
                self::fail('Expected SearchIndexError.');
            } catch (SearchIndexError) {
            }
        }
        unset($source['status']);
        $this->expectException(SearchIndexError::class);
        ProductIndexDefinition::document($source);
    }

    public function testADocumentWithoutAFromPriceHasNoSuchField(): void
    {
        $this->maintenance()->setup();
        $document = self::document(fromGross: null, variants: [new VariantDocument(null, false, [])]);

        $this->indexer()->index($document);

        $stored = self::esClient()->get(['index' => $this->alias(), 'id' => self::ID]);
        \assert($stored instanceof Elasticsearch);
        $source = $stored['_source'];
        self::assertIsArray($source);
        self::assertArrayNotHasKey('from_gross', $source);
        self::assertSame([['purchasable' => false, 'options' => [], 'axes' => [], 'pairs' => []]], $source['variants']);
        self::assertArrayNotHasKey('max_gross', $source);
        self::assertFalse($source['purchasable']);
        self::assertSame([], $source['buy_prices']);
        self::assertEquals($document, ProductIndexDefinition::document($source));
    }

    public function testVariantConditionsMatchOneVariantNotTwo(): void
    {
        $this->maintenance()->setup();
        $this->indexer()->index(self::document());
        self::esClient()->indices()->refresh(['index' => $this->alias()]);

        // Black exists and a purchasable variant exists, but not a purchasable black one.
        self::assertSame(0, $this->countVariants([
            ['term' => ['variants.options' => "Kolor\u{1F}czarne"]],
            ['term' => ['variants.purchasable' => true]],
        ]));
        self::assertSame(1, $this->countVariants([
            ['term' => ['variants.options' => "Kolor\u{1F}Białe"]],
            ['term' => ['variants.purchasable' => true]],
            ['range' => ['variants.gross' => ['lte' => 19_900]]],
        ]));
        // Options keep the case of Catalog.
        self::assertSame(0, $this->countVariants([['term' => ['variants.options' => "Kolor\u{1F}białe"]]]));
    }

    public function testPairsOfAVariantAreMatchedTogetherNotAcrossVariants(): void
    {
        $this->maintenance()->setup();
        $this->indexer()->index(self::document());
        self::esClient()->indices()->refresh(['index' => $this->alias()]);

        $pair = static fn (string $axis, string $value): array => ['nested' => ['path' => 'variants.pairs', 'query' => ['bool' => ['filter' => [
            ['term' => ['variants.pairs.axis' => $axis]],
            ['term' => ['variants.pairs.value' => $value]],
        ]]]]];

        // Kolor czarne and Rozmiar 42 are one variant; Kolor czarne and Rozmiar 43 are two.
        self::assertSame(1, $this->countVariants([$pair('Kolor', 'czarne'), ['term' => ['variants.options' => "Rozmiar\u{1F}42"]]]));
        self::assertSame(0, $this->countVariants([$pair('Kolor', 'czarne'), ['term' => ['variants.options' => "Rozmiar\u{1F}43"]]]));
        // The pair is a value of its own: the axis name is not a value and the case is kept.
        self::assertSame(0, $this->countVariants([$pair('Kolor', 'Rozmiar')]));
        self::assertSame(0, $this->countVariants([$pair('Kolor', 'białe')]));
    }

    public function testTheFacetTokensAreStoredAndFoundByTermsAndRanges(): void
    {
        $this->maintenance()->setup();
        $this->indexer()->index(self::document());
        self::esClient()->indices()->refresh(['index' => $this->alias()]);

        $source = self::esClient()->get(['index' => $this->alias(), 'id' => self::ID]);
        \assert($source instanceof Elasticsearch);
        self::assertTrue(self::at($source->asArray(), '_source', 'purchasable'));
        self::assertSame([19_900], self::at($source->asArray(), '_source', 'buy_prices'));
        self::assertSame(19_900, self::at($source->asArray(), '_source', 'max_gross'));

        // The token of an option holds the separator U+001F, the one of a pair U+001E; both are found as they are.
        self::assertSame(1, $this->countMatching(['term' => ['facets' => "Kolor\u{1F}czarne"]]));
        self::assertSame(1, $this->countMatching(['term' => ['facets' => '@Rozmiar']]));
        self::assertSame(1, $this->countMatching(['term' => ['facets' => '#p']]));
        self::assertSame(1, $this->countMatching(['term' => ['facets' => '$']]));
        self::assertSame(0, $this->countMatching(['term' => ['facets' => "Kolor\u{1F}biale"]]));
        // Only the purchasable variant feeds the buy fields.
        self::assertSame(0, $this->countMatching(['term' => ['buy_facets' => "Kolor\u{1F}czarne"]]));
        self::assertSame(1, $this->countMatching(['term' => ['buy_facets' => "Kolor\u{1F}Białe"]]));
        self::assertSame(0, $this->countMatching(['term' => ['buy_facets' => '$']]));
        self::assertSame(1, $this->countMatching(['term' => ['pair_buy' => "Kolor\u{1F}Białe\u{1E}Rozmiar\u{1F}43"]]));
        self::assertSame(0, $this->countMatching(['term' => ['pair_buy' => "Kolor\u{1F}czarne\u{1E}Rozmiar\u{1F}42"]]));
        self::assertSame(1, $this->countMatching(['term' => ['pair_any' => "Kolor\u{1F}czarne\u{1E}Rozmiar\u{1F}42"]]));
        // The same two options of two different variants are not a pair.
        self::assertSame(0, $this->countMatching(['term' => ['pair_any' => "Kolor\u{1F}czarne\u{1E}Rozmiar\u{1F}43"]]));
        self::assertSame(1, $this->countMatching(['term' => ['purchasable' => true]]));
        self::assertSame(1, $this->countMatching(['range' => ['buy_prices' => ['gte' => 19_000, 'lte' => 20_000]]]));
        self::assertSame(0, $this->countMatching(['range' => ['buy_prices' => ['gte' => 18_000, 'lte' => 19_000]]]));
        self::assertSame(1, $this->countMatching(['term' => ['max_gross' => 19_900]]));
    }

    public function testThePatternsOfTheTokensMatchOnlyWhatTheyShouldAmongNamesWithSyntaxCharacters(): void
    {
        $this->maintenance()->setup();
        $this->indexer()->index(self::document(variants: [
            new VariantDocument(100, true, ["Kolor\u{1F}czarne", "Wymiar (cm)\u{1F}50.*"]),
            new VariantDocument(200, true, ["Kolor\u{1F}czarne", "Wymiar (cm)\u{1F}500"]),
            new VariantDocument(300, false, ["Kolor ramy\u{1F}złoty"]),
            new VariantDocument(400, true, ["#Kolor\u{1F}\$x"]),
        ]));
        self::esClient()->indices()->refresh(['index' => $this->alias()]);

        // An axis is not matched by another axis that starts with its name, nor by a dot or a bracket standing for
        // characters.
        self::assertEqualsCanonicalizing(["Kolor\u{1F}czarne"], $this->buckets('facets', ProductFacetTokens::optionsOfAxisPattern('Kolor')));
        self::assertEqualsCanonicalizing(["Kolor ramy\u{1F}złoty"], $this->buckets('facets', ProductFacetTokens::optionsOfAxisPattern('Kolor ramy')));
        self::assertEqualsCanonicalizing(["Wymiar (cm)\u{1F}50.*", "Wymiar (cm)\u{1F}500"], $this->buckets('facets', ProductFacetTokens::optionsOfAxisPattern('Wymiar (cm)')));
        self::assertSame([], $this->buckets('facets', ProductFacetTokens::optionsOfAxisPattern('Wymiar .cm.')));
        self::assertSame([], $this->buckets('facets', ProductFacetTokens::optionsOfAxisPattern('.*')));
        self::assertEqualsCanonicalizing(["#Kolor\u{1F}\$x"], $this->buckets('facets', ProductFacetTokens::optionsOfAxisPattern('#Kolor')));
        self::assertSame([], $this->buckets('facets', ProductFacetTokens::optionsOfAxisPattern('Kolo')));

        // The partners of one option on pair_any: only the tokens that begin with that option and the separator.
        self::assertEqualsCanonicalizing(
            ["Kolor\u{1F}czarne\u{1E}Wymiar (cm)\u{1F}50.*", "Kolor\u{1F}czarne\u{1E}Wymiar (cm)\u{1F}500", "Kolor\u{1F}czarne\u{1E}@Wymiar (cm)"],
            $this->buckets('pair_any', ProductFacetTokens::pairPartnersPattern('Kolor', 'czarne')),
        );
        self::assertEqualsCanonicalizing(
            ["Wymiar (cm)\u{1F}50.*\u{1E}Kolor\u{1F}czarne", "Wymiar (cm)\u{1F}50.*\u{1E}@Kolor"],
            $this->buckets('pair_any', ProductFacetTokens::pairPartnersPattern('Wymiar (cm)', '50.*')),
        );
        self::assertSame([], $this->buckets('pair_any', ProductFacetTokens::pairPartnersPattern('Wymiar (cm)', '50.')));
    }

    public function testAProductBreakingARuleOfTheModelIsRefusedAndNothingIsWritten(): void
    {
        $this->maintenance()->setup();
        $document = self::document(variants: [
            new VariantDocument(100, true, ["Kolor\u{1F}czarne"]),
            new VariantDocument(200, true, ["Kolor\u{1F}czarne"]),
        ]);

        try {
            $this->indexer()->index($document);
            self::fail('Expected SearchIndexError.');
        } catch (SearchIndexError $e) {
            self::assertStringContainsString(self::ID, $e->getMessage());
        }
        self::assertSame(0, $this->countAll());
    }

    /**
     * The value at the nested keys of a decoded response, null when any level is missing.
     */
    private static function at(mixed $data, string ...$keys): mixed
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
     * @return list<string>
     */
    private function tokens(string $field, string $text): array
    {
        $response = self::esClient()->indices()->analyze(['index' => $this->alias(), 'body' => ['field' => $field, 'text' => $text]]);
        \assert($response instanceof Elasticsearch);
        $tokens = $response->asArray()['tokens'] ?? null;
        \assert(\is_array($tokens));

        return array_map(static fn (mixed $token): string => \is_array($token) && \is_string($token['token']) ? $token['token'] : '', array_values($tokens));
    }

    /**
     * @param list<array<string, mixed>> $conditions
     */
    private function countVariants(array $conditions): int
    {
        $response = self::esClient()->count(['index' => $this->alias(), 'body' => [
            'query' => ['nested' => ['path' => 'variants', 'query' => ['bool' => ['filter' => $conditions]]]],
        ]]);
        \assert($response instanceof Elasticsearch);
        $count = $response['count'];
        \assert(\is_int($count));

        return $count;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function countMatching(array $query): int
    {
        $response = self::esClient()->count(['index' => $this->alias(), 'body' => ['query' => $query]]);
        \assert($response instanceof Elasticsearch);
        $count = $response['count'];
        \assert(\is_int($count));

        return $count;
    }

    private function countAll(): int
    {
        self::esClient()->indices()->refresh(['index' => $this->alias()]);

        return $this->countMatching(['match_all' => new \stdClass()]);
    }

    /**
     * The terms of a field that match the regular expression, across all documents.
     *
     * @return list<string>
     */
    private function buckets(string $field, string $include): array
    {
        $response = self::esClient()->search(['index' => $this->alias(), 'body' => [
            'size' => 0,
            'aggs' => ['tokens' => ['terms' => ['field' => $field, 'include' => $include, 'size' => 100]]],
        ]]);
        \assert($response instanceof Elasticsearch);
        $buckets = self::at($response->asArray(), 'aggregations', 'tokens', 'buckets');
        \assert(\is_array($buckets));

        return array_values(array_map(static fn (mixed $bucket): string => \is_array($bucket) && \is_string($bucket['key'] ?? null) ? $bucket['key'] : '', $buckets));
    }

    private function indexer(): ElasticsearchProductIndexer
    {
        return new ElasticsearchProductIndexer(self::clientFactory()->create(2.0), $this->alias());
    }

    /**
     * @param list<VariantDocument>|null $variants
     */
    private static function document(?int $fromGross = 19_900, ?array $variants = null, ProductStatus $status = ProductStatus::Published): ProductDocument
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
                new VariantDocument(18_900, false, ["Kolor\u{1F}czarne", "Rozmiar\u{1F}42"]),
                new VariantDocument(19_900, true, ["Kolor\u{1F}Białe", "Rozmiar\u{1F}43"]),
                new VariantDocument(null, false, ["Kolor\u{1F}zielone", "Rozmiar\u{1F}44"]),
            ],
            fromGross: $fromGross,
            status: $status,
        );
    }
}
