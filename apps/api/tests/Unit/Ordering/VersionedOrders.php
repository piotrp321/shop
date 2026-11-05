<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;

/**
 * Orders that behave like the database: get() returns a fresh copy at the stored version, and save() refuses a copy
 * whose version is no longer the stored one, then raises the stored version. confirmElsewhere() is another handler
 * saving the marker first.
 */
final class VersionedOrders implements OrderRepository
{
    /** @var array<string, array{order: Order, version: int}> */
    private array $stored = [];

    public function add(Order $order): void
    {
        $this->stored[$order->id()->toString()] = ['order' => $order, 'version' => $order->version()];
    }

    public function save(Order $order): void
    {
        $id = $order->id()->toString();
        $stored = $this->stored[$id] ?? throw new OrderNotFound();
        if ($stored['version'] !== $order->version()) {
            throw new OrderModifiedConcurrently();
        }
        if (null !== $order->confirmationSentAt()) {
            $stored['order']->markConfirmationSent($order->confirmationSentAt());
        }
        $this->stored[$id]['version'] = $stored['version'] + 1;
    }

    public function get(OrderId $id): Order
    {
        $stored = $this->stored[$id->toString()] ?? throw new OrderNotFound();
        $order = $stored['order'];

        return Order::reconstitute(
            $order->id(),
            $order->number(),
            $order->owner(),
            $order->contact(),
            $order->shippingAddress(),
            $order->lines(),
            $order->status(),
            $order->idempotencyKey(),
            $order->requestFingerprint(),
            $order->placedAt(),
            $order->confirmationSentAt(),
            $stored['version'],
        );
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?Order
    {
        return null;
    }

    public function confirmElsewhere(OrderId $id, \DateTimeImmutable $at): void
    {
        $copy = $this->get($id);
        $copy->markConfirmationSent($at);
        $this->save($copy);
    }
}
