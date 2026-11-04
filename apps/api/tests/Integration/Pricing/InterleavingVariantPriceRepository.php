<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing;

use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Price\VariantPrice;
use App\Pricing\Domain\Price\VariantPriceRepository;

/**
 * Runs a competing request once, right after the handler under test has read the price and before it saves.
 */
final class InterleavingVariantPriceRepository implements VariantPriceRepository
{
    /**
     * @param \Closure(): void $competingRequest
     */
    public function __construct(
        private readonly VariantPriceRepository $inner,
        private ?\Closure $competingRequest,
    ) {
    }

    public function find(VariantId $id): ?VariantPrice
    {
        $price = $this->inner->find($id);
        $competingRequest = $this->competingRequest;
        $this->competingRequest = null;
        if (null !== $competingRequest) {
            $competingRequest();
        }

        return $price;
    }

    public function findMany(array $ids): array
    {
        return $this->inner->findMany($ids);
    }

    public function page(?VariantId $after, int $limit): array
    {
        return $this->inner->page($after, $limit);
    }

    public function save(VariantPrice $price): void
    {
        $this->inner->save($price);
    }
}
