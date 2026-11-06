<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Application\View\OrderView;

/**
 * The subject and the plain text of the email sent once an unpaid order is cancelled, in Polish.
 */
final readonly class OrderCancelledText
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
            'Nie otrzymaliśmy płatności w terminie, więc anulowaliśmy zamówienie.',
        ];

        return implode("\n", $lines)."\n";
    }
}
