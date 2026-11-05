<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\OrderPrices;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\VariantId;

final class InMemoryOrderPrices implements OrderPrices
{
    /** @var array<string, PriceCopy> */
    private array $prices = [];
    /** @var list<list<string>> */
    public array $asked = [];

    public function __construct(private readonly Journal $journal)
    {
    }

    public function price(string $variantId, int $net, int $vat, int $vatRateBp = 2300, string $taxClass = 'standard'): self
    {
        $this->prices[$variantId] = PriceCopy::of($net, $vat, $net + $vat, $vatRateBp, $taxClass, Currency::PLN);

        return $this;
    }

    public function current(array $variantIds): array
    {
        $this->journal->record('prices');
        $this->asked[] = array_map(static fn (VariantId $id): string => $id->toString(), $variantIds);

        $found = [];
        foreach ($variantIds as $id) {
            $price = $this->prices[$id->toString()] ?? null;
            if (null !== $price) {
                $found[$id->toString()] = $price;
            }
        }

        return $found;
    }
}
