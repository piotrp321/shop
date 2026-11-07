<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Application\View\OrderView;

/**
 * The subject and the plain text of the email sent once staff cancelled a paid order, in Polish. It promises the
 * refund without its due date (the panel watches it).
 */
final readonly class OrderCancelledByStaffText
{
    public function subject(OrderView $order): string
    {
        return 'Zamówienie '.$order->number.' anulowane';
    }

    public function body(OrderView $order): string
    {
        $lines = [
            'Dzień dobry '.$order->fullName.',',
            '',
            'Numer zamówienia: '.$order->number,
            '',
            'Anulowaliśmy Twoje zamówienie. Zwrócimy płatność.',
        ];

        return implode("\n", $lines)."\n";
    }
}
