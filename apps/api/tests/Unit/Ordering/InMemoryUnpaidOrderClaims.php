<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\UnpaidOrderClaims;
use App\Ordering\Application\Port\UnpaidOrderLock;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\UnpaidOrderIdentity;

/**
 * The claims of unpaid orders and their locks in memory: the locks are only journaled (one process), a count takes the
 * claims whose expiry is after the moment given, as the table does.
 */
final class InMemoryUnpaidOrderClaims implements UnpaidOrderClaims, UnpaidOrderLock
{
    /** @var array<string, array{address: string, identity: string, expiresAt: \DateTimeImmutable}> by order id */
    public array $claims = [];

    /** @var list<array{string, string}> the address and the identity of every lock, in order */
    public array $locked = [];

    /** @var list<\DateTimeImmutable> the moments the counts were made at */
    public array $countedAt = [];

    public function __construct(private readonly Journal $journal = new Journal())
    {
    }

    public function lock(string $address, UnpaidOrderIdentity $identity): void
    {
        $this->journal->record('lock-unpaid');
        $this->locked[] = [$address, $identity->key()];
    }

    public function countByAddress(string $address, \DateTimeImmutable $now): int
    {
        $this->journal->record('count-address');
        $this->countedAt[] = $now;

        return \count(array_filter($this->claims, static fn (array $claim): bool => $claim['address'] === $address && $claim['expiresAt'] > $now));
    }

    public function countByIdentity(UnpaidOrderIdentity $identity, \DateTimeImmutable $now): int
    {
        $this->journal->record('count-identity');
        $this->countedAt[] = $now;

        return \count(array_filter($this->claims, static fn (array $claim): bool => $claim['identity'] === $identity->key() && $claim['expiresAt'] > $now));
    }

    public function add(OrderId $orderId, string $address, UnpaidOrderIdentity $identity, \DateTimeImmutable $expiresAt): void
    {
        $this->journal->record('claim');
        $this->claims[$orderId->toString()] = ['address' => $address, 'identity' => $identity->key(), 'expiresAt' => $expiresAt];
    }

    public function remove(OrderId $orderId): void
    {
        $this->journal->record('unclaim');
        unset($this->claims[$orderId->toString()]);
    }

    /**
     * Claims of other orders, as earlier placements leave them.
     */
    public function existing(int $count, string $address, string $identity, \DateTimeImmutable $expiresAt): self
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->claims['existing-'.\count($this->claims)] = ['address' => $address, 'identity' => $identity, 'expiresAt' => $expiresAt];
        }

        return $this;
    }
}
