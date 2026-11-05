<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Cart;

use App\Cart\Application\CartRef;
use App\Cart\Application\Command\CartLineForOrder;
use App\Cart\Application\Command\ClearCartAfterOrder;
use App\Cart\Application\Command\LockCartForOrder;
use App\Ordering\Application\CartReference;
use App\Ordering\Application\Port\CartLineToOrder;
use App\Ordering\Application\Port\OrderCart;
use App\Ordering\Domain\VariantId;
use App\Ordering\Infrastructure\BusFailure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The cart of an order through Cart's commands LockCartForOrder and ClearCartAfterOrder on command.bus, dispatched
 * inside the transaction of PlaceOrder (nested, a savepoint on the same connection). Cart's exceptions (CartNotFound,
 * GuestCartMerged) are passed on as they are.
 */
final class CommandBusOrderCart implements OrderCart
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'command.bus')] MessageBusInterface $commandBus)
    {
        $this->messageBus = $commandBus;
    }

    public function lockForOrder(CartReference $cart): array
    {
        try {
            $lines = $this->handle(new LockCartForOrder(self::ref($cart)));
        } catch (HandlerFailedException $failure) {
            throw BusFailure::cause($failure);
        }
        \assert(\is_array($lines));

        return array_map(static function (mixed $line): CartLineToOrder {
            \assert($line instanceof CartLineForOrder);

            return new CartLineToOrder(VariantId::fromString($line->variantId), $line->quantity);
        }, array_values($lines));
    }

    public function clearAfterOrder(CartReference $cart): void
    {
        try {
            $this->handle(new ClearCartAfterOrder(self::ref($cart)));
        } catch (HandlerFailedException $failure) {
            throw BusFailure::cause($failure);
        }
    }

    private static function ref(CartReference $cart): CartRef
    {
        return null !== $cart->customerId ? CartRef::customer($cart->customerId) : CartRef::guest($cart->cartId ?? '');
    }
}
