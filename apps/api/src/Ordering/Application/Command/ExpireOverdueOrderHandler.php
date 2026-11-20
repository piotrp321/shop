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
 * Cancels an overdue unpaid order by the same UnpaidOrderExpiration as ExpireUnpaidOrder, in the transaction of
 * command.bus, and tells the console whether it did: a paid or cancelled order (also one the delayed message or a
 * payment changed while the command waited for its lock) is left as it is.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ExpireOverdueOrderHandler
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
     * @return bool whether the order was cancelled now
     *
     * @throws OrderModifiedConcurrently the order changed meanwhile; the console retries it once
     */
    public function __invoke(ExpireOverdueOrder $command): bool
    {
        return $this->expiration->expire($command->orderId);
    }
}
