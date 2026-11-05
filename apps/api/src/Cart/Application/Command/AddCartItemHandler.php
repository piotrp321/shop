<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\LockedCarts;
use App\Cart\Application\Port\Clock;
use App\Cart\Application\ProductDataLoader;
use App\Cart\Application\View\CartView;
use App\Cart\Application\View\CartViewBuilder;
use App\Cart\Domain\CartRepository;
use App\Cart\Domain\ProductNotFound;
use App\Cart\Domain\Quantity;
use App\Cart\Domain\VariantId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class AddCartItemHandler
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
     * The ports are asked once, for the lines and the added variant together: the offer of the variant and the view
     * come from the same reads.
     */
    public function __invoke(AddCartItem $command): CartView
    {
        $now = $this->clock->now();
        $quantity = Quantity::of($command->quantity);
        $cart = $this->locked->forChange($command->cart, $now, true);
        \assert(null !== $cart);
        try {
            $variantId = VariantId::fromString($command->variantId);
        } catch (\InvalidArgumentException) {
            throw new ProductNotFound();
        }

        $data = $this->products->load([...$cart->variantIds(), $variantId]);
        if (null === $data->summary($variantId)) {
            throw new ProductNotFound();
        }
        $cart->add($variantId, $quantity, $data->offerFor($variantId), $now);
        $this->carts->save($cart);

        return $this->views->build($cart->id(), $cart->lines(), $data);
    }
}
