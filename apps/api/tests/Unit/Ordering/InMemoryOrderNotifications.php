<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\NotificationClaim;
use App\Ordering\Application\Port\NotificationKind;
use App\Ordering\Application\Port\OrderNotifications;
use App\Ordering\Domain\OrderId;

/**
 * The sending lease as DbalOrderNotifications keeps it, in memory: a claim holds the sending for LEASE unless the
 * email went out or another claim has not expired.
 */
final class InMemoryOrderNotifications implements OrderNotifications
{
    /** @var array<string, array{claimedUntil: ?\DateTimeImmutable, sentAt: ?\DateTimeImmutable}> */
    private array $rows = [];

    /** @var list<string> "<kind> <order id>" of every release, in order */
    public array $releases = [];

    public function claim(OrderId $orderId, NotificationKind $kind, \DateTimeImmutable $now): NotificationClaim
    {
        $key = self::key($orderId, $kind);
        $row = $this->rows[$key] ?? ['claimedUntil' => null, 'sentAt' => null];
        if (null !== $row['sentAt']) {
            return NotificationClaim::alreadySent();
        }
        if (null !== $row['claimedUntil'] && $row['claimedUntil'] > $now) {
            return NotificationClaim::inProgress($row['claimedUntil']);
        }
        $this->rows[$key] = ['claimedUntil' => $now->modify(self::LEASE), 'sentAt' => null];

        return NotificationClaim::claimed();
    }

    public function markSent(OrderId $orderId, NotificationKind $kind, \DateTimeImmutable $now): void
    {
        $key = self::key($orderId, $kind);
        $this->rows[$key] = ['claimedUntil' => null, 'sentAt' => $this->rows[$key]['sentAt'] ?? $now];
    }

    public function release(OrderId $orderId, NotificationKind $kind): void
    {
        $key = self::key($orderId, $kind);
        $this->releases[] = $key;
        if (isset($this->rows[$key]) && null === $this->rows[$key]['sentAt']) {
            $this->rows[$key]['claimedUntil'] = null;
        }
    }

    public function sentAt(string $orderId, NotificationKind $kind): ?\DateTimeImmutable
    {
        return $this->rows[self::key(OrderId::fromString($orderId), $kind)]['sentAt'] ?? null;
    }

    public function claimedUntil(string $orderId, NotificationKind $kind): ?\DateTimeImmutable
    {
        return $this->rows[self::key(OrderId::fromString($orderId), $kind)]['claimedUntil'] ?? null;
    }

    /**
     * Whether anything was ever claimed or marked for the order.
     */
    public function touched(string $orderId): bool
    {
        foreach (array_keys($this->rows) as $key) {
            if (str_ends_with($key, ' '.$orderId)) {
                return true;
            }
        }

        return false;
    }

    private static function key(OrderId $orderId, NotificationKind $kind): string
    {
        return $kind->value.' '.$orderId->toString();
    }
}
