<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\VariantSource;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\VariantId;

/**
 * An in-memory VariantSource over a list of products with their variants. It answers like the database adapter:
 * in no particular order for the many-id reads, ordered by variant id for the pages. A product without `status` is
 * published.
 */
final class FakeVariantSource implements VariantSource
{
    /** @var list<string> the method of every call */
    public array $calls = [];

    /**
     * @param list<array{
     *     id: string,
     *     slug: string,
     *     name: string,
     *     taxClass: string,
     *     axes: list<string>,
     *     mainImage: array{key: string, alt: string}|null,
     *     variants: list<array{id: string, values: list<string>}>,
     *     status?: 'draft'|'published'
     * }> $products
     */
    public function __construct(private readonly array $products)
    {
    }

    public function summaries(array $ids): array
    {
        $this->calls[] = 'summaries';
        $wanted = array_map(static fn (VariantId $id): string => $id->toString(), $ids);
        $rows = [];
        foreach (array_reverse($this->products) as $product) {
            foreach (array_reverse($product['variants']) as $variant) {
                if (\in_array($variant['id'], $wanted, true)) {
                    $rows[] = [
                        'id' => $variant['id'],
                        'productId' => $product['id'],
                        'name' => $product['name'],
                        'values' => $variant['values'],
                        'mainImage' => $product['mainImage'],
                        'published' => self::published($product),
                    ];
                }
            }
        }

        return $rows;
    }

    public function find(VariantId $id): ?array
    {
        $this->calls[] = 'find';
        foreach ($this->products as $product) {
            foreach ($product['variants'] as $variant) {
                if ($variant['id'] === $id->toString()) {
                    return ['id' => $variant['id'], 'productId' => $product['id'], 'taxClass' => $product['taxClass']];
                }
            }
        }

        return null;
    }

    public function ofProducts(array $ids): array
    {
        $this->calls[] = 'ofProducts';
        $wanted = array_map(static fn (ProductId $id): string => $id->toString(), $ids);
        $rows = [];
        foreach (array_reverse($this->products) as $product) {
            if (\in_array($product['id'], $wanted, true)) {
                $rows[] = [
                    'productId' => $product['id'],
                    'axes' => $product['axes'],
                    'taxClass' => $product['taxClass'],
                    'published' => self::published($product),
                    'variants' => $product['variants'],
                ];
            }
        }

        return $rows;
    }

    public function page(?VariantId $after, int $limit): array
    {
        $this->calls[] = 'page';
        $rows = [];
        foreach ($this->products as $product) {
            foreach ($product['variants'] as $variant) {
                if (null === $after || strcmp($variant['id'], $after->toString()) > 0) {
                    $rows[$variant['id']] = [
                        'id' => $variant['id'],
                        'productSlug' => $product['slug'],
                        'values' => $variant['values'],
                        'taxClass' => $product['taxClass'],
                    ];
                }
            }
        }
        ksort($rows);

        return \array_slice(array_values($rows), 0, $limit);
    }

    /**
     * @param array{status?: 'draft'|'published'} $product
     */
    private static function published(array $product): bool
    {
        return 'draft' !== ($product['status'] ?? 'published');
    }
}
