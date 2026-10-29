<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

use App\Catalog\Application\Search\ProductDocument;
use App\Catalog\Application\Search\SearchIndexError;

/**
 * Settings and mapping of a physical product index. The hash of the definition (mapping and analysis, without
 * shards and replicas) is stored in the index's _meta.definition_hash, so an index built from another definition
 * is detected at setup and before switching the alias.
 */
final readonly class ProductIndexDefinition
{
    private const string SORT_NORMALIZER = 'sort_normalizer';

    public function __construct(private int $shards, private int $replicas)
    {
    }

    public function hash(): string
    {
        return hash('sha256', json_encode([self::analysis(), self::mappings()], \JSON_THROW_ON_ERROR));
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
                'analysis' => self::analysis(),
            ],
            'mappings' => ['_meta' => ['definition_hash' => $this->hash()], ...self::mappings()],
        ];
    }

    /**
     * The _source of a document.
     *
     * @return array<string, mixed>
     */
    public static function source(ProductDocument $document): array
    {
        return [
            'id' => $document->id,
            'version' => $document->version,
            'name' => $document->name,
            'slug' => $document->slug,
            'short_description' => $document->shortDescription,
            'main_category_id' => $document->mainCategoryId,
            'category_ids' => $document->categoryIds,
            'category_ancestor_ids' => $document->categoryAncestorIds,
            'main_image' => $document->mainImage,
        ];
    }

    /**
     * @throws SearchIndexError when the _source does not have the shape written by {@see source()}
     */
    public static function document(mixed $source): ProductDocument
    {
        if (!\is_array($source)
            || !\is_string($source['id'] ?? null) || !\is_int($source['version'] ?? null)
            || !\is_string($source['name'] ?? null) || !\is_string($source['slug'] ?? null)
            || !\is_string($source['short_description'] ?? null) || !\is_string($source['main_category_id'] ?? null)
            || !self::isStringList($source['category_ids'] ?? null) || !self::isStringList($source['category_ancestor_ids'] ?? null)
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
            $source['category_ids'],
            $source['category_ancestor_ids'],
            $mainImage,
        );
    }

    /**
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isStringList(mixed $value): bool
    {
        return \is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $item): bool => \is_string($item));
    }

    /**
     * @return array<string, mixed>
     */
    private static function analysis(): array
    {
        return [
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

        return [
            'dynamic' => 'strict',
            'properties' => [
                'id' => $keyword,
                'version' => ['type' => 'long'],
                'name' => [
                    'type' => 'text',
                    'fields' => ['sort' => ['type' => 'keyword', 'normalizer' => self::SORT_NORMALIZER]],
                ],
                'slug' => $keyword,
                'short_description' => ['type' => 'text', 'index' => false],
                'main_category_id' => $keyword,
                // All categories of the product, the main one first.
                'category_ids' => $keyword,
                // The product's categories and all their ancestors; the category filter matches here.
                'category_ancestor_ids' => $keyword,
                // Stored for display only: {key, alt}.
                'main_image' => ['type' => 'object', 'enabled' => false],
            ],
        ];
    }
}
