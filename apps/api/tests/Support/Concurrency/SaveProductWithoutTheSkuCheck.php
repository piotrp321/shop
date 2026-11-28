<?php

declare(strict_types=1);

namespace App\Tests\Support\Concurrency;

/**
 * Message handled by {@see SaveProductWithoutTheSkuCheckHandler}: creates a draft product with one variant per given SKU
 * straight through the repository, without the check of the SKUs that the handler of `CreateProduct` makes first. It stands
 * for a write whose check found the SKUs free before another write committed them (the check reads committed rows only),
 * so the unique index of the SKU and the locks of the repository are all that is left between the two.
 */
final readonly class SaveProductWithoutTheSkuCheck
{
    /**
     * @param list<string> $skus              one variant per SKU, in this order
     * @param int          $delayMilliseconds how long the handler waits before it saves, so that another write that started
     *                                        at the same moment is certain to be first
     */
    public function __construct(
        public string $productId,
        public string $slug,
        public string $categoryId,
        public array $skus,
        public int $delayMilliseconds = 0,
    ) {
    }
}
