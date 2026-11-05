<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\LockedCarts;
use App\Cart\Application\Port\Clock;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\CartNotFound;
use App\Cart\Domain\GuestCartMerged;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class LockCartForOrderHandler
{
    public function __construct(
        private LockedCarts $locked,
        private Clock $clock,
    ) {
    }

    /**
     * The lock is that of every Cart command (a customer without a cart gets none), so a change of the cart and
     * another order from it wait until the transaction ends. Nothing is saved: an expired customer cart only reads
     * as empty here.
     *
     * @return list<CartLineForOrder> in the order of addedAt; none for a customer without a cart or with an expired one
     *
     * @throws CartNotFound    an unknown or expired guest cart, or the id of a customer cart
     * @throws GuestCartMerged
     */
    public function __invoke(LockCartForOrder $command): array
    {
        $now = $this->clock->now();
        $cart = $this->locked->forChange($command->cart, $now, false);
        if (null === $cart) {
            return [];
        }

        return array_map(
            static fn (CartLine $line): CartLineForOrder => new CartLineForOrder($line->variantId()->toString(), $line->quantity()->value()),
            $cart->linesAt($now),
        );
    }
}
