<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Application\View\OrderView;

/**
 * The subject and the plain text of the email sent once staff shipped an order, with the address it goes to, in
 * Polish.
 */
final readonly class OrderShippedText
{
    public function subject(OrderView $order): string
    {
        return 'Zamówienie '.$order->number.' wysłane';
    }

    public function body(OrderView $order): string
    {
        $address = $order->shippingAddress;
        $lines = [
            'Dzień dobry '.$order->fullName.',',
            '',
            'Numer zamówienia: '.$order->number,
            '',
            'Zamówienie zostało wysłane na adres:',
            $order->fullName,
            $address->street,
            $address->postalCode.' '.$address->city,
        ];

        return implode("\n", $lines)."\n";
    }
}
