<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\LockedCarts;
use App\Cart\Application\Port\Clock;
use App\Cart\Domain\CartNotFound;
use App\Cart\Domain\CartRepository;
use App\Cart\Domain\GuestCartMerged;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ClearCartAfterOrderHandler
{
    public function __construct(
        private LockedCarts $locked,
        private CartRepository $carts,
        private Clock $clock,
    ) {
    }

    /**
     * Locks the cart like every Cart command; in the transaction that already locked it, that refreshes it under the
     * same lock. A customer without a cart changes nothing.
     *
     * @throws CartNotFound
     * @throws GuestCartMerged
     */
    public function __invoke(ClearCartAfterOrder $command): void
    {
        $now = $this->clock->now();
        $cart = $this->locked->forChange($command->cart, $now, false);
        if (null === $cart) {
            return;
        }

        $cart->clearAfterOrder($now);
        $this->carts->save($cart);
    }
}
