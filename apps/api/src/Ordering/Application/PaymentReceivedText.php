<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Application\View\OrderView;

/**
 * The subject and the plain text of the email sent once an order is paid, in Polish (MailFormat).
 */
final readonly class PaymentReceivedText
{
    public function subject(OrderView $order): string
    {
        return 'Płatność przyjęta – zamówienie '.$order->number;
    }

    public function body(OrderView $order): string
    {
        $lines = [
            'Dzień dobry '.$order->fullName.',',
            '',
            'otrzymaliśmy płatność za zamówienie.',
            '',
            'Numer zamówienia: '.$order->number,
            'Kwota: '.MailFormat::amount($order->totals->gross),
            '',
            'Zamówienie przekazujemy do realizacji.',
        ];

        return implode("\n", $lines)."\n";
    }
}
