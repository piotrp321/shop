<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart;

use App\Cart\Application\Port\CartReader;
use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartRepository;

/**
 * Carts kept as copies, so a change is stored only by add() or save(), as with a database.
 */
final class InMemoryCarts implements CartRepository, CartReader
{
    /** @var array<string, Cart> */
    private array $carts = [];
    public int $saves = 0;

    public function lock(CartId $id): ?Cart
    {
        return $this->find($id);
    }

    public function lockForCustomer(string $customerId): ?Cart
    {
        return $this->findForCustomer($customerId);
    }

    public function lockOrCreateForCustomer(string $customerId, \DateTimeImmutable $now): Cart
    {
        $cart = $this->findForCustomer($customerId);
        if (null === $cart) {
            $cart = Cart::createForCustomer(CartId::generate(), $customerId, $now);
            $this->carts[$cart->id()->toString()] = clone $cart;
        }

        return $cart;
    }

    public function add(Cart $cart): void
    {
        $this->carts[$cart->id()->toString()] = clone $cart;
    }

    public function save(Cart $cart): void
    {
        ++$this->saves;
        $this->carts[$cart->id()->toString()] = clone $cart;
    }

    public function find(CartId $id): ?Cart
    {
        $cart = $this->carts[$id->toString()] ?? null;

        return null === $cart ? null : clone $cart;
    }

    public function findForCustomer(string $customerId): ?Cart
    {
        foreach ($this->carts as $cart) {
            if ($cart->owner()->customerId() === $customerId) {
                return clone $cart;
            }
        }

        return null;
    }

    public function count(): int
    {
        return \count($this->carts);
    }
}
