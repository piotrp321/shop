<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Search\ProductSearchSnapshot;

final class InMemoryProductSearchSource implements ProductSearchSource
{
    /** @var list<array{string|null, int}> every page() call: [afterId, limit] */
    public array $calls = [];

    /** @var (\Closure(): void)|null runs on every page() call, before the answer (e.g. to move a clock) */
    public ?\Closure $onRead = null;

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

    /** @var list<list<string>> every findMany() call */
    public array $findManyCalls = [];

    public function findMany(array $ids): array
    {
        $this->findManyCalls[] = $ids;
        if (null !== $this->onRead) {
            ($this->onRead)();
        }
        $wanted = array_map('strtolower', $ids);

        return array_values(array_filter($this->snapshots, static fn (ProductSearchSnapshot $s): bool => \in_array($s->id, $wanted, true)));
    }

    public function count(): int
    {
        return \count($this->snapshots);
    }

    public function idsAfter(?string $afterId, int $limit): array
    {
        return array_map(static fn (ProductSearchSnapshot $s): string => $s->id, $this->page($afterId, $limit));
    }

    public function page(?string $afterId, int $limit): array
    {
        $this->calls[] = [$afterId, $limit];
        if (null !== $this->onRead) {
            ($this->onRead)();
        }

        return \array_slice(
            array_values(array_filter($this->snapshots, static fn (ProductSearchSnapshot $s): bool => null === $afterId || $s->id > $afterId)),
            0,
            $limit,
        );
    }
}
