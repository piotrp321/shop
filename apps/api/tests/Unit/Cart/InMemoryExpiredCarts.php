<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Cart\Application\Port\ExpiredCarts;

/**
 * Cart expiry times kept by cart id; purgeBatch() removes the earliest expired ones, as the database does.
 */
final class InMemoryExpiredCarts implements ExpiredCarts
{
    /** @var array<string, \DateTimeImmutable> */
    private array $expiries = [];

    public function cart(string $id, string $expiresAt): self
    {
        $this->expiries[$id] = new \DateTimeImmutable($expiresAt);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->expiries);
    }

    public function purgeBatch(\DateTimeImmutable $now, int $limit): int
    {
        $expired = array_filter($this->expiries, static fn (\DateTimeImmutable $expiresAt): bool => $expiresAt < $now);
        asort($expired);
        $batch = \array_slice(array_keys($expired), 0, $limit);
        foreach ($batch as $id) {
            unset($this->expiries[$id]);
        }

        return \count($batch);
    }
}
