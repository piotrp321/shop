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
 * Orders that behave like the database: get() and lock() return a fresh copy at the stored version (lock() also
 * records the id), and save() refuses a copy whose version is no longer the stored one, then stores the copy and raises the stored version. changeElsewhere()
 * is another handler saving a change first.
 */
final class VersionedOrders implements OrderRepository
{
    /** @var array<string, array{order: Order, version: int}> */
    private array $stored = [];

    /** @var list<string> ids of the orders saved, in the order of the saves */
    public array $saved = [];

    /** @var list<string> ids of the orders locked, in the order of the locks */
    public array $locked = [];

    /** Runs once at the start of the next save, e.g. to let a concurrent handler save first. */
    public ?\Closure $beforeSave = null;

    public function add(Order $order): void
    {
        $this->stored[$order->id()->toString()] = ['order' => OrderExamples::copy($order), 'version' => $order->version()];
    }

    public function save(Order $order): void
    {
        if (null !== $this->beforeSave) {
            $beforeSave = $this->beforeSave;
            $this->beforeSave = null;
            $beforeSave();
        }
        $id = $order->id()->toString();
        $stored = $this->stored[$id] ?? throw new OrderNotFound();
        if ($stored['version'] !== $order->version()) {
            throw new OrderModifiedConcurrently();
        }
        $this->stored[$id] = ['order' => OrderExamples::copy($order), 'version' => $stored['version'] + 1];
        $this->saved[] = $id;
    }

    public function get(OrderId $id): Order
    {
        $stored = $this->stored[$id->toString()] ?? throw new OrderNotFound();

        return OrderExamples::copy($stored['order'], $stored['version']);
    }

    public function lock(OrderId $id): Order
    {
        $this->locked[] = $id->toString();

        return $this->get($id);
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?Order
    {
        return null;
    }

    /**
     * Another handler reads the order, changes it and saves it first.
     *
     * @param \Closure(Order): mixed $change
     */
    public function changeElsewhere(OrderId $id, \Closure $change): void
    {
        $copy = $this->get($id);
        $change($copy);
        $this->save($copy);
    }
}
