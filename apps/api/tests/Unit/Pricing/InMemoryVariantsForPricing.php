<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\CatalogVariant;
use App\Pricing\Application\Port\VariantsForPricing;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Tax\TaxClass;

final class InMemoryVariantsForPricing implements VariantsForPricing
{
    /** @var list<int> the limit of every page asked for */
    public array $pageLimits = [];
    /** @var list<CatalogVariant> ordered by id */
    private array $variants = [];

    /**
     * @param array<string, string> $keys       variant id => key (`<product slug>/<label slug>`)
     * @param array<string, string> $taxClasses variant id => tax class code of its product; `standard` when absent
     */
    public function __construct(array $keys = [], array $taxClasses = [])
    {
        ksort($keys);
        foreach ($keys as $id => $key) {
            $this->variants[] = new CatalogVariant(VariantId::fromString($id), $key, TaxClass::fromString($taxClasses[$id] ?? 'standard'));
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
