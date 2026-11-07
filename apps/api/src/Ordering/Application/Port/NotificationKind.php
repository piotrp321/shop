<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * The emails an order gets, each at most once (OrderNotifications). The values are those of
 * ordering_order_notification.kind.
 */
enum NotificationKind: string
{
    case Confirmation = 'confirmation';
    case PaymentReceived = 'payment_received';
    case Cancelled = 'cancelled';
    case Shipped = 'shipped';
    case ReturnReceived = 'return_received';
}
