<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Application\View\OrderView;

/**
 * The subject and the plain text of the email sent once staff registered the return of an order, in Polish. It does
 * not say the customer sent the goods back: a parcel that was never delivered comes back too.
 */
final readonly class ReturnReceivedText
{
    public function subject(OrderView $order): string
    {
        return 'Zamówienie '.$order->number.' wróciło do nas';
    }

    public function body(OrderView $order): string
    {
        $lines = [
            'Dzień dobry '.$order->fullName.',',
            '',
            'Zamówienie '.$order->number.' wróciło do nas. Zwrócimy płatność.',
        ];

        return implode("\n", $lines)."\n";
    }
}
