<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Application\Port\Clock;
use App\Ordering\Application\Port\DiscountCodeRedemptions;
use App\Ordering\Application\Port\StockReservations;
use App\Ordering\Application\Port\UnpaidOrderClaims;
use App\Ordering\Application\Port\UnpaidOrderExpiry;
use App\Ordering\Domain\OrderModifiedConcurrently;
use App\Ordering\Domain\OrderRepository;
use App\Shared\Domain\Event\DomainEventPublisher;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Cancels an unpaid order once its deadline and the window for the webhooks have passed, by UnpaidOrderExpiration in the
 * transaction of command.bus: the delayed message of the placement. A message that came too early is sent again for a
 * second after the expiry; a version conflict (the order paid meanwhile) is thrown, and Messenger retries the message,
 * which then sees the paid order.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ExpireUnpaidOrderHandler
{
    private UnpaidOrderExpiration $expiration;

    public function __construct(
        OrderRepository $orders,
        StockReservations $reservations,
        DiscountCodeRedemptions $redemptions,
        UnpaidOrderExpiry $expiry,
        DomainEventPublisher $events,
        Clock $clock,
        LoggerInterface $logger,
        UnpaidOrderClaims $unpaidClaims,
    ) {
        $this->expiration = new UnpaidOrderExpiration($orders, $reservations, $redemptions, $expiry, $events, $clock, $logger, $unpaidClaims);
    }

    /**
     * @throws OrderModifiedConcurrently the order changed meanwhile; Messenger retries the message
     */
    public function __invoke(ExpireUnpaidOrder $command): void
    {
        $this->expiration->expire($command->orderId);
    }
}
