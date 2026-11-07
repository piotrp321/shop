<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Application\Port\VariantSummaries;
use App\Pricing\Application\Port\VariantSummary;
use App\Pricing\Domain\Price\VariantId;

final class InMemoryVariantSummaries implements VariantSummaries
{
    /** @var array<string, VariantSummary> */
    private array $summaries = [];
    /** @var list<list<string>> the ids of every call */
    public array $asked = [];

    public function add(string $variantId, string $productId, string $productName, string $label): void
    {
        $this->summaries[$variantId] = new VariantSummary(VariantId::fromString($variantId), $productId, $productName, $label);
    }

    public function of(array $variantIds): array
    {
        $this->asked[] = array_map(static fn (VariantId $id): string => $id->toString(), $variantIds);
        $found = [];
        foreach ($variantIds as $id) {
            if (isset($this->summaries[$id->toString()])) {
                $found[$id->toString()] = $this->summaries[$id->toString()];
            }
        }

        return $found;
    }
}
