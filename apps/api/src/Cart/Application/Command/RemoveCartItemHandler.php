<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\LockedCarts;
use App\Cart\Application\Port\Clock;
use App\Cart\Application\ProductDataLoader;
use App\Cart\Application\View\CartView;
use App\Cart\Application\View\CartViewBuilder;
use App\Cart\Domain\CartRepository;
use App\Cart\Domain\VariantId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RemoveCartItemHandler
{
    public function __construct(
        private LockedCarts $locked,
        private CartRepository $carts,
        private ProductDataLoader $products,
        private CartViewBuilder $views,
        private Clock $clock,
    ) {
    }

    /**
     * A customer without a cart gets the empty view and no cart; a missing line (also an id that is no UUID) is
     * a success without change.
     */
    public function __invoke(RemoveCartItem $command): CartView
    {
        $now = $this->clock->now();
        $cart = $this->locked->forChange($command->cart, $now, false);
        if (null === $cart) {
            return $this->views->empty(null);
        }
        try {
            $cart->remove(VariantId::fromString($command->variantId), $now);
        } catch (\InvalidArgumentException) {
            // Not a variant id, so not in the cart.
        }
        $this->carts->save($cart);

        return $this->views->build($cart->id(), $cart->lines(), $this->products->load($cart->variantIds()));
    }
}
