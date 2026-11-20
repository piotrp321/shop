<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\Port\OverdueOrder;
use App\Ordering\Application\Port\OverdueOrderIds;

/**
 * New orders by their deadline, read like the DBAL adapter reads them (deadline before the boundary, oldest first by
 * placed_at and id, after the keyset), with every call recorded.
 */
final class InMemoryOverdueOrderIds implements OverdueOrderIds
{
    /** @var list<array{OverdueOrder, \DateTimeImmutable}> the order and its deadline */
    private array $orders = [];
    /** @var list<array{\DateTimeImmutable, ?OverdueOrder, int}> deadlineBefore, after and limit of every call */
    public array $calls = [];

    public function add(string $orderId, \DateTimeImmutable $placedAt): void
    {
        $this->orders[] = [new OverdueOrder($orderId, $placedAt), $placedAt->modify('+30 minutes')];
    }

    public function page(\DateTimeImmutable $deadlineBefore, ?OverdueOrder $after, int $limit): array
    {
        $this->calls[] = [$deadlineBefore, $after, $limit];
        $found = array_values(array_filter(
            $this->orders,
            static fn (array $entry): bool => $entry[1] < $deadlineBefore && (null === $after || [$entry[0]->placedAt, $entry[0]->orderId] > [$after->placedAt, $after->orderId]),
        ));
        usort($found, static fn (array $a, array $b): int => [$a[0]->placedAt, $a[0]->orderId] <=> [$b[0]->placedAt, $b[0]->orderId]);

        return \array_slice(array_map(static fn (array $entry): OverdueOrder => $entry[0], $found), 0, $limit);
    }
}
