<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Port\ProductSearchSource;
use App\Catalog\Application\Search\ProductSearchSnapshot;

/**
 * A set of products whose current state a callback reads at every call, so a test (or another process, through
 * files) can change a product between the reads of a rebuild and of a single write; a test can also create one.
 */
final class CallbackProductSearchSource implements ProductSearchSource
{
    /** @var list<string> sorted */
    private array $ids;

    /**
     * @param list<string>                            $ids  lowercase UUIDs
     * @param \Closure(string): ProductSearchSnapshot $read the current state of the product with this id
     */
    public function __construct(array $ids, private readonly \Closure $read)
    {
        sort($ids);
        $this->ids = $ids;
    }

    /**
     * Creates a product: later reads see it.
     *
     * @param string $id a lowercase UUID
     */
    public function add(string $id): void
    {
        $ids = [...$this->ids, $id];
        sort($ids);
        $this->ids = $ids;
    }

    public function page(?string $afterId, int $limit): array
    {
        $ids = \array_slice(array_values(array_filter($this->ids, static fn (string $id): bool => null === $afterId || $id > strtolower($afterId))), 0, $limit);

        return array_map(fn (string $id): ProductSearchSnapshot => ($this->read)($id), $ids);
    }

    public function findMany(array $ids): array
    {
        $wanted = array_map('strtolower', $ids);

        return array_map(fn (string $id): ProductSearchSnapshot => ($this->read)($id), array_values(array_filter($this->ids, static fn (string $id): bool => \in_array($id, $wanted, true))));
    }

    public function count(): int
    {
        return \count($this->ids);
    }

    public function idsAfter(?string $afterId, int $limit): array
    {
        return \array_slice(array_values(array_filter($this->ids, static fn (string $id): bool => null === $afterId || $id > strtolower($afterId))), 0, $limit);
    }

    public function find(string $id): ?ProductSearchSnapshot
    {
        return \in_array(strtolower($id), $this->ids, true) ? ($this->read)(strtolower($id)) : null;
    }
}
