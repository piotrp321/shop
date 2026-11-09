<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Pricing;

use App\Ordering\Application\Port\DiscountCodeRedemptions;
use App\Ordering\Domain\DiscountCodeRejected;
use App\Ordering\Domain\OrderId;
use App\Ordering\Infrastructure\BusFailure;
use App\Pricing\Application\Command\DiscountCodeRejected as PricingDiscountCodeRejected;
use App\Pricing\Application\Command\RedeemDiscountCode;
use App\Pricing\Application\Command\ReleaseDiscountCodeUsage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The usages of discount codes through Pricing's RedeemDiscountCode (nested in the transaction of PlaceOrder) and
 * ReleaseDiscountCodeUsage (nested in the transaction of ExpireUnpaidOrder) on command.bus. A rejection of Pricing
 * becomes DiscountCodeRejected of Ordering with Pricing's type, detail and minimum, thrown on so the whole placement
 * rolls back; every other failure is passed on as it is.
 */
final readonly class CommandBusDiscountCodeRedemptions implements DiscountCodeRedemptions
{
    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus)
    {
    }

    public function redeem(string $codeId, OrderId $orderId, int $subtotalGross): void
    {
        try {
            $this->commandBus->dispatch(new RedeemDiscountCode($codeId, $orderId->toString(), $subtotalGross));
        } catch (HandlerFailedException $failure) {
            $cause = BusFailure::cause($failure);
            if ($cause instanceof PricingDiscountCodeRejected) {
                throw DiscountCodeRejected::of($cause->type, $cause->getMessage(), $cause->minimumOrderGross);
            }

            throw $cause;
        }
    }

    public function release(OrderId $orderId): void
    {
        try {
            $this->commandBus->dispatch(new ReleaseDiscountCodeUsage($orderId->toString()));
        } catch (HandlerFailedException $failure) {
            throw BusFailure::cause($failure);
        }
    }
}
