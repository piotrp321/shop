<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\IdempotencyKeyReused;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;

/**
 * Orders by id; a second order with a key already used is refused like the unique index refuses it.
 */
final class InMemoryOrders implements OrderRepository
{
    /** @var array<string, Order> */
    private array $orders = [];

    public function __construct(private readonly Journal $journal)
    {
    }

    public function add(Order $order): void
    {
        $this->journal->record('add');
        if (null !== $this->findByKey($order->idempotencyKey())) {
            throw new IdempotencyKeyReused();
        }
        $this->orders[$order->id()->toString()] = $order;
    }

    public function save(Order $order): void
    {
        $this->orders[$order->id()->toString()] = $order;
    }

    public function get(OrderId $id): Order
    {
        return $this->orders[$id->toString()] ?? throw new OrderNotFound();
    }

    public function findByIdempotencyKey(IdempotencyKey $key): ?Order
    {
        $this->journal->record('find');

        return $this->findByKey($key);
    }

    /**
     * @return list<Order>
     */
    public function all(): array
    {
        return array_values($this->orders);
    }

    private function findByKey(IdempotencyKey $key): ?Order
    {
        foreach ($this->orders as $order) {
            if ($order->idempotencyKey()->toString() === $key->toString()) {
                return $order;
            }
        }

        return null;
    }
}
