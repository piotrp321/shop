<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\LockedCarts;
use App\Cart\Application\Port\Clock;
use App\Cart\Application\ProductDataLoader;
use App\Cart\Application\View\CartView;
use App\Cart\Application\View\CartViewBuilder;
use App\Cart\Domain\CartRepository;
use App\Cart\Domain\ProductId;
use App\Cart\Domain\ProductNotFound;
use App\Cart\Domain\Quantity;
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
     * The ports are asked once, for the lines and the added product together: the offer of the product and the view
     * come from the same reads.
     */
    public function __invoke(AddCartItem $command): CartView
    {
        $now = $this->clock->now();
        $quantity = Quantity::of($command->quantity);
        $cart = $this->locked->forChange($command->cart, $now, true);
        \assert(null !== $cart);
        try {
            $productId = ProductId::fromString($command->productId);
        } catch (\InvalidArgumentException) {
            throw new ProductNotFound();
        }

        $data = $this->products->load([...$cart->productIds(), $productId]);
        if (null === $data->summary($productId)) {
            throw new ProductNotFound();
        }
        $cart->add($productId, $quantity, $data->offerFor($productId), $now);
        $this->carts->save($cart);

        return $this->views->build($cart->id(), $cart->lines(), $data);
    }
}
