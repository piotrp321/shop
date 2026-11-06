<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Port\ProductOfferFacts;
use App\Catalog\Application\Search\ProductFacts;

/**
 * Offer facts from a map of product id => facts; records every call and throws the queued failure instead.
 */
final class InMemoryProductOfferFacts implements ProductOfferFacts
{
    /** @var list<list<string>> the product ids of every call */
    public array $asked = [];

    public ?\Throwable $failure = null;

    /** @var (\Closure(): void)|null runs on every call, before the answer (e.g. to move a clock) */
    public ?\Closure $onRead = null;

    /**
     * @param array<string, ProductFacts> $facts
     */
    public function __construct(public array $facts = [])
    {
    }

    public function of(array $productIds): array
    {
        $this->asked[] = $productIds;
        if (null !== $this->onRead) {
            ($this->onRead)();
        }
        if (null !== $this->failure) {
            throw $this->failure;
        }

        return array_intersect_key($this->facts, array_flip($productIds));
    }
}
