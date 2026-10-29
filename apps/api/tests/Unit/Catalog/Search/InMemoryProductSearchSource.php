<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Search\ProductSearchSnapshot;

final class InMemoryProductSearchSource implements ProductSearchSource
{
    /** @var list<array{string|null, int}> every page() call: [afterId, limit] */
    public array $calls = [];

    /** @var list<ProductSearchSnapshot> sorted by id */
    private array $snapshots;

    /**
     * @param list<ProductSearchSnapshot> $snapshots
     */
    public function __construct(array $snapshots)
    {
        usort($snapshots, static fn (ProductSearchSnapshot $a, ProductSearchSnapshot $b): int => $a->id <=> $b->id);
        $this->snapshots = $snapshots;
    }

    public function find(string $id): ?ProductSearchSnapshot
    {
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->id === strtolower($id)) {
                return $snapshot;
            }
        }

        return null;
    }

    public function page(?string $afterId, int $limit): array
    {
        $this->calls[] = [$afterId, $limit];

        return \array_slice(
            array_values(array_filter($this->snapshots, static fn (ProductSearchSnapshot $s): bool => null === $afterId || $s->id > $afterId)),
            0,
            $limit,
        );
    }
}
