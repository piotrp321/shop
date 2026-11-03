<?php

declare(strict_types=1);

namespace App\Cart\Application\Query;

use App\Cart\Application\Port\CartReader;
use App\Cart\Application\Port\Clock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCartSummaryHandler
{
    public function __construct(
        private CartReader $carts,
        private Clock $clock,
    ) {
    }

    /**
     * One read of the cart, no port. The same rules as GetCart.
     */
    public function __invoke(GetCartSummary $query): CartSummary
    {
        $cart = ReadableCarts::find($this->carts, $query->cart);

        return new CartSummary(null === $cart ? 0 : $cart->itemCountAt($this->clock->now()));
    }
}
