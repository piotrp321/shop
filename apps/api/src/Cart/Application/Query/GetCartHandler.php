<?php

declare(strict_types=1);

namespace App\Cart\Application\Query;

use App\Cart\Application\Port\CartReader;
use App\Cart\Application\Port\Clock;
use App\Cart\Application\ProductDataLoader;
use App\Cart\Application\View\CartView;
use App\Cart\Application\View\CartViewBuilder;
use App\Cart\Domain\CartLine;
use App\Cart\Domain\VariantId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetCartHandler
{
    public function __construct(
        private CartReader $carts,
        private ProductDataLoader $products,
        private CartViewBuilder $views,
        private Clock $clock,
    ) {
    }

    /**
     * Creates nothing: a customer without a cart gets the empty view without id, an expired customer cart is empty.
     * The code is quoted only with a purchasable line.
     */
    public function __invoke(GetCart $query): CartView
    {
        $cart = ReadableCarts::find($this->carts, $query->cart);
        if (null === $cart) {
            return $this->views->empty(null);
        }

        $lines = $cart->linesAt($this->clock->now());

        return $this->views->build($cart->id(), $lines, $this->products->load(array_map(static fn (CartLine $line): VariantId => $line->variantId(), $lines)), $query->discountCode);
    }
}
