<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\LockedCarts;
use App\Cart\Application\Port\Clock;
use App\Cart\Application\ProductData;
use App\Cart\Application\ProductDataLoader;
use App\Cart\Application\View\CartView;
use App\Cart\Application\View\CartViewBuilder;
use App\Cart\Domain\CartLineNotFound;
use App\Cart\Domain\CartRepository;
use App\Cart\Domain\ProductNotFound;
use App\Cart\Domain\Quantity;
use App\Cart\Domain\VariantId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class ChangeCartItemQuantityHandler
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
     * Only an increase needs the offer and a variant Catalog knows; a decrease is always possible.
     */
    public function __invoke(ChangeCartItemQuantity $command): CartView
    {
        $now = $this->clock->now();
        $quantity = Quantity::of($command->quantity);
        $cart = $this->locked->forChange($command->cart, $now, true);
        \assert(null !== $cart);
        try {
            $variantId = VariantId::fromString($command->variantId);
        } catch (\InvalidArgumentException) {
            throw new CartLineNotFound();
        }

        $data = null;
        $offer = null;
        if ($cart->needsOfferToChange($variantId, $quantity)) {
            $data = $this->products->load($cart->variantIds());
            if (null === $data->summary($variantId)) {
                throw new ProductNotFound();
            }
            $offer = $data->offerFor($variantId);
        }
        $cart->changeQuantity($variantId, $quantity, $offer, $now);
        $this->carts->save($cart);

        return $this->views->build($cart->id(), $cart->lines(), $data instanceof ProductData ? $data : $this->products->load($cart->variantIds()));
    }
}
