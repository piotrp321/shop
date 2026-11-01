<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing;

use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\ProductPrice;
use App\Pricing\Domain\Price\ProductPriceRepository;

/**
 * Runs a competing request once, right after the handler under test has read the price and before it saves.
 */
final class InterleavingProductPriceRepository implements ProductPriceRepository
{
    /**
     * @param \Closure(): void $competingRequest
     */
    public function __construct(
        private readonly ProductPriceRepository $inner,
        private ?\Closure $competingRequest,
    ) {
    }

    public function find(ProductId $id): ?ProductPrice
    {
        $price = $this->inner->find($id);
        $competingRequest = $this->competingRequest;
        $this->competingRequest = null;
        if (null !== $competingRequest) {
            $competingRequest();
        }

        return $price;
    }

    public function save(ProductPrice $price): void
    {
        $this->inner->save($price);
    }
}
