<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;
use App\Catalog\Application\Search\VariantDocument;
use App\Catalog\Domain\Product\ProductStatus;

/**
 * Settings and mapping of a physical product index. The hash of the definition (mapping, analysis and the sorting of
 * the index, without shards, replicas and the refresh interval) is stored in the index's _meta.definition_hash, so an
 * index built from another definition is detected at setup and before switching the alias. The sorting is a static
 * setting of an index, so changing it takes a rebuild; the refresh interval is dynamic, setup applies it to the index
 * that exists.
 */
final readonly class ProductIndexDefinition
{
    private const string SORT_NORMALIZER = 'sort_normalizer';
    private const string POLISH_TEXT = 'polish_text';
    private const string FOLDED_TEXT = 'folded_text';
    // Documents of one category lie next to each other in the segments (spec M3).
    private const string SORT_FIELD = 'sort_path';
    // A whole number with ms or s; \z, not $, which also matches before a final newline.
    private const string REFRESH_INTERVAL_FORMAT = '/\A[1-9][0-9]*(?:ms|s)\z/';

    /**
     * @param int    $shards          the primary shards of a new index, from SEARCH_SHARDS; an index that exists keeps its own
     * @param int    $replicas        the replicas of every shard
     * @param string $refreshInterval the refresh interval of the index once it is filled, from SEARCH_REFRESH_INTERVAL
     */
    public function __construct(private int $shards, private int $replicas, private string $refreshInterval = '1s')
    {
        if ($shards < 1) {
            // The container refuses an empty or non-numeric SEARCH_SHARDS itself; 0 and less would reach Elasticsearch,
            // whose refusal does not name the variable.
            throw new \InvalidArgumentException(\sprintf('SEARCH_SHARDS must be a whole number of at least 1, got %d.', $shards));
        }
        if ($replicas < 0) {
            throw new \InvalidArgumentException(\sprintf('The number of replicas of the search index must not be negative, got %d.', $replicas));
        }
        // The container passes the variable as it is, so the format is checked here, when the definition is built.
        if (1 !== preg_match(self::REFRESH_INTERVAL_FORMAT, $refreshInterval)) {
            throw new \InvalidArgumentException(\sprintf('SEARCH_REFRESH_INTERVAL must be a whole number of at least 1 with the unit ms or s (for example 1s or 500ms), got %s.', json_encode($refreshInterval, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)));
        }
    }

    public function hash(): string
    {
        return hash('sha256', json_encode([self::analysis(), self::mappings(), self::sorting()], \JSON_THROW_ON_ERROR));
    }

    /**
     * The refresh interval of an index that is filled and in use.
     */
    public function refreshInterval(): string
    {
        return $this->refreshInterval;
    }

    /**
     * The create-index request body, with the definition hash in _meta.
     *
     * @return array{settings: array<string, mixed>, mappings: array<string, mixed>}
     */
    public function body(): array
    {
        return [
            'settings' => [
                'number_of_shards' => $this->shards,
                'number_of_replicas' => $this->replicas,
                'refresh_interval' => $this->refreshInterval,
                'analysis' => self::analysis(),
                ...self::sorting(),
            ],
            'mappings' => ['_meta' => ['definition_hash' => $this->hash()], ...self::mappings()],
        ];
    }

    /**
     * The _source of a document. The fields derived from the variants (the axes of a variant, the tokens, the prices to
     * filter by) are written here and never read back by {@see document()}.
     *
     * @return array<string, mixed>
     *
     * @throws SearchIndexError when the product breaks a rule of the facet model ({@see ProductFacetTokens::of()})
     */
    public static function source(ProductDocument $document): array
    {
        // Refuses a product the facet model cannot count correctly, before anything is written.
        $tokens = ProductFacetTokens::of($document);

        return [
            'id' => $document->id,
            'version' => $document->version,
            'name' => $document->name,
            'slug' => $document->slug,
            'short_description' => $document->shortDescription,
            'main_category_id' => $document->mainCategoryId,
            self::SORT_FIELD => $document->mainCategoryPath,
            'category_ids' => $document->categoryIds,
            'category_ancestor_ids' => $document->categoryAncestorIds,
            'main_image' => $document->mainImage,
            'category_names' => $document->categoryNames,
            'variants' => array_map(static fn (VariantDocument $variant, array $axes): array => [
                // No price: no field, so a price range never matches the variant.
                ...(null === $variant->gross ? [] : ['gross' => $variant->gross]),
                'purchasable' => $variant->purchasable,
                'options' => $variant->options,
                'axes' => $axes,
            ], $document->variants, $tokens->variantAxes),
            'facets' => $tokens->facets,
            'buy_facets' => $tokens->buyFacets,
            'pair_any' => $tokens->pairAny,
            'pair_buy' => $tokens->pairBuy,
            'purchasable' => $tokens->purchasable,
            'buy_prices' => $tokens->buyPrices,
            // No purchasable variant, no field.
            ...(null === $tokens->maxGross ? [] : ['max_gross' => $tokens->maxGross]),
            ...(null === $document->fromGross ? [] : ['from_gross' => $document->fromGross]),
            'status' => $document->status->value,
        ];
    }

    /**
     * @throws SearchIndexError when the _source does not have the shape written by {@see source()}
     */
    public static function document(mixed $source): ProductDocument
    {
        $fromGross = \is_array($source) ? ($source['from_gross'] ?? null) : null;
        $status = \is_array($source) && \is_string($source['status'] ?? null) ? ProductStatus::tryFrom($source['status']) : null;
        if (!\is_array($source) || null === $status
            || !\is_string($source['id'] ?? null) || !\is_int($source['version'] ?? null)
            || !\is_string($source['name'] ?? null) || !\is_string($source['slug'] ?? null)
            || !\is_string($source['short_description'] ?? null) || !\is_string($source['main_category_id'] ?? null)
            || !\is_string($source[self::SORT_FIELD] ?? null)
            || !self::isStringList($source['category_ids'] ?? null) || !self::isStringList($source['category_ancestor_ids'] ?? null)
            || !self::isStringList($source['category_names'] ?? null) || !\is_array($source['variants'] ?? null)
            || !array_is_list($source['variants']) || !(null === $fromGross || \is_int($fromGross))
        ) {
            throw new SearchIndexError('A search document does not match the index definition.');
        }

        $image = $source['main_image'] ?? null;
        $mainImage = \is_array($image) && \is_string($image['key'] ?? null) && \is_string($image['alt'] ?? null)
            ? ['key' => $image['key'], 'alt' => $image['alt']]
            : null;

        return new ProductDocument(
            $source['id'],
            $source['version'],
            $source['name'],
            $source['slug'],
            $source['short_description'],
            $source['main_category_id'],
            $source[self::SORT_FIELD],
            $source['category_ids'],
            $source['category_ancestor_ids'],
            $mainImage,
            $source['category_names'],
            array_map(self::variant(...), $source['variants']),
            $fromGross,
            $status,
        );
    }

    /**
     * @throws SearchIndexError when the variant does not have the shape written by {@see source()}
     */
    private static function variant(mixed $variant): VariantDocument
    {
        $gross = \is_array($variant) ? ($variant['gross'] ?? null) : null;
        if (!\is_array($variant) || !\is_bool($variant['purchasable'] ?? null)
            || !self::isStringList($variant['options'] ?? null)
            || !(null === $gross || \is_int($gross))
        ) {
            throw new SearchIndexError('A search document does not match the index definition.');
        }

        return new VariantDocument($gross, $variant['purchasable'], $variant['options']);
    }

    /**
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isStringList(mixed $value): bool
    {
        return \is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => \is_string($item));
    }

    /**
     * The sorting of the index: a static setting, so part of the definition hash.
     *
     * @return array{'sort.field': string, 'sort.order': string}
     */
    private static function sorting(): array
    {
        return ['sort.field' => self::SORT_FIELD, 'sort.order' => 'asc'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function analysis(): array
    {
        return [
            'analyzer' => [
                // Polish inflection: "kurtki" and "kurtka" give the same token (polish_stem of analysis-stempel).
                self::POLISH_TEXT => ['type' => 'custom', 'tokenizer' => 'standard', 'filter' => ['lowercase', 'polish_stem', 'asciifolding']],
                // Without the stemmer: a phrase typed without Polish characters ("spiwor"), and forms the stemmer
                // does not reduce.
                self::FOLDED_TEXT => ['type' => 'custom', 'tokenizer' => 'standard', 'filter' => ['lowercase', 'asciifolding']],
            ],
            'normalizer' => [
                // "Żel", "buty", "Ajax" sort as "Ajax", "buty", "Żel".
                self::SORT_NORMALIZER => ['type' => 'custom', 'filter' => ['lowercase', 'asciifolding']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function mappings(): array
    {
        $keyword = ['type' => 'keyword'];
        $folded = ['folded' => ['type' => 'text', 'analyzer' => self::FOLDED_TEXT]];

        return [
            'dynamic' => 'strict',
            'properties' => [
                'id' => $keyword,
                'version' => ['type' => 'long'],
                'name' => [
                    'type' => 'text',
                    'analyzer' => self::POLISH_TEXT,
                    'fields' => ['sort' => ['type' => 'keyword', 'normalizer' => self::SORT_NORMALIZER], ...$folded],
                ],
                'slug' => $keyword,
                'short_description' => ['type' => 'text', 'analyzer' => self::POLISH_TEXT, 'fields' => $folded],
                'main_category_id' => $keyword,
                // The ids of the main category's path from the root joined with "/": the sort key of the index, never
                // searched.
                self::SORT_FIELD => ['type' => 'keyword', 'index' => false],
                // All categories of the product, the main one first.
                'category_ids' => $keyword,
                // The product's categories and all their ancestors; the category filter matches here.
                'category_ancestor_ids' => $keyword,
                // Stored for display only: {key, alt}.
                'main_image' => ['type' => 'object', 'enabled' => false],
                // The names of the product's categories and all their ancestors.
                'category_names' => ['type' => 'text', 'analyzer' => self::POLISH_TEXT, 'fields' => $folded],
                // Nested, so conditions on axes, price and availability hold for one and the same variant.
                'variants' => [
                    'type' => 'nested',
                    'properties' => [
                        // Absent without a price.
                        'gross' => ['type' => 'long'],
                        'purchasable' => ['type' => 'boolean'],
                        // "<axis>" U+001F "<value>", as in Catalog (case kept); the variant conditions match here.
                        'options' => $keyword,
                        // The axes of the variant, for the facet of an axis with several values of one axis: read only by a
                        // terms aggregation on its doc values, never searched.
                        'axes' => ['type' => 'keyword', 'index' => false],
                    ],
                ],
                // The tokens of the product for conditions and facets in one pass (see ProductFacetTokens): `facets` of
                // all variants, `buy_facets` of the purchasable ones; `pair_*` pair the options of one variant.
                'facets' => $keyword,
                'buy_facets' => $keyword,
                'pair_any' => $keyword,
                'pair_buy' => $keyword,
                // Some variant can be bought.
                'purchasable' => ['type' => 'boolean'],
                // The distinct prices of the purchasable variants; only a range filter reads them.
                'buy_prices' => ['type' => 'long', 'doc_values' => false],
                // The highest price of the purchasable variants; absent without one.
                'max_gross' => ['type' => 'long'],
                // The lowest price of the purchasable variants; absent without one.
                'from_gross' => ['type' => 'long'],
                // `published` or `draft`: every query of the store matches `published` only, a draft stays as a
                // tombstone with its version.
                'status' => $keyword,
            ],
        ];
    }
}
