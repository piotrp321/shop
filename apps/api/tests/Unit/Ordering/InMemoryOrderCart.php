<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Application\CartReference;
use App\Ordering\Application\Port\CartLineToOrder;
use App\Ordering\Application\Port\OrderCart;
use App\Ordering\Domain\VariantId;

/**
 * Carts by their reference. A cart marked gone (unknown, expired or merged guest cart) throws on every call; a
 * customer without a cart has no line.
 */
final class InMemoryOrderCart implements OrderCart
{
    /** @var array<string, list<CartLineToOrder>> */
    private array $carts = [];
    /** @var array<string, true> */
    private array $gone = [];
    /** @var (\Closure(): void)|null */
    public ?\Closure $whileLocking = null;

    public function __construct(private readonly Journal $journal)
    {
    }

    /**
     * @param array<string, int> $lines quantity by variant id, in the order they were added
     */
    public function put(CartReference $ref, array $lines): self
    {
        $this->carts[$ref->normalised()] = array_map(
            static fn (string $variantId, int $quantity): CartLineToOrder => new CartLineToOrder(VariantId::fromString($variantId), $quantity),
            array_keys($lines),
            array_values($lines),
        );
        unset($this->gone[$ref->normalised()]);

        return $this;
    }

    public function markGone(CartReference $ref): void
    {
        $this->gone[$ref->normalised()] = true;
    }

    public function lockForOrder(CartReference $cart): array
    {
        $this->journal->record('lock');
        $this->refuseGone($cart);
        if (null !== $this->whileLocking) {
            ($this->whileLocking)();
        }

        return $this->carts[$cart->normalised()] ?? [];
    }

    public function clearAfterOrder(CartReference $cart): void
    {
        $this->journal->record('clear');
        $this->refuseGone($cart);
        if (isset($this->carts[$cart->normalised()])) {
            $this->carts[$cart->normalised()] = [];
        }
    }

    /**
     * @return array<string, int> quantity by variant id
     */
    public function lines(CartReference $ref): array
    {
        $lines = [];
        foreach ($this->carts[$ref->normalised()] ?? [] as $line) {
            $lines[$line->variantId->toString()] = $line->quantity;
        }

        return $lines;
    }

    private function refuseGone(CartReference $cart): void
    {
        if (isset($this->gone[$cart->normalised()])) {
            throw new CartGone();
        }
    }
}
