<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Application\Port\CatalogVariant;
use App\Inventory\Application\Port\VariantsForInventory;
use App\Inventory\Domain\Stock\VariantId;

final class InMemoryVariantsForInventory implements VariantsForInventory
{
    /** @var list<int> the limit of every page asked for */
    public array $pageLimits = [];
    /** @var list<CatalogVariant> ordered by id */
    private array $variants = [];

    /**
     * @param array<string, string> $keys variant id => key
     */
    public function __construct(array $keys = [])
    {
        ksort($keys);
        foreach ($keys as $id => $key) {
            $this->variants[] = new CatalogVariant(VariantId::fromString($id), $key);
        }
    }

    public function page(?VariantId $after, int $limit): array
    {
        $this->pageLimits[] = $limit;
        $rest = null === $after
            ? $this->variants
            : array_values(array_filter($this->variants, static fn (CatalogVariant $variant): bool => $variant->id->toString() > $after->toString()));

        return \array_slice($rest, 0, $limit);
    }
}
