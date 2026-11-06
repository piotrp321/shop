<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Shared\Infrastructure\Http\Cache\StorefrontCache;
use App\Shared\Infrastructure\Http\Cache\StorefrontCacheFailure;

/**
 * Records the invalidations instead of sending them; fails every call while $failure is set.
 */
final class SpyStorefrontCache implements StorefrontCache
{
    /** @var list<string> "product:<id>", "purgeAll" or "revalidateAll", in order */
    public array $calls = [];

    public ?StorefrontCacheFailure $failure = null;

    public function invalidateProduct(string $productId): void
    {
        $this->record('product:'.$productId);
    }

    public function purgeAll(): void
    {
        $this->record('purgeAll');
    }

    public function revalidateAll(): void
    {
        $this->record('revalidateAll');
    }

    private function record(string $call): void
    {
        $this->calls[] = $call;
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
