<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

use App\Cart\Application\Port\Clock;
use App\Cart\Application\View\CartView;
use App\Cart\Application\View\CartViewBuilder;
use App\Cart\Domain\Cart;
use App\Cart\Domain\CartId;
use App\Cart\Domain\CartRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateGuestCartHandler
{
    public function __construct(
        private CartRepository $carts,
        private CartViewBuilder $views,
        private Clock $clock,
    ) {
    }

    public function __invoke(CreateGuestCart $command): CartView
    {
        $cart = Cart::createForGuest(CartId::generate(), $this->clock->now());
        $this->carts->add($cart);

        return $this->views->empty($cart->id());
    }
}
