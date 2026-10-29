<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Search;

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
