<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Application\View\OrderView;

/**
 * The subject and the plain text of the email sent once staff shipped an order, with the address it goes to, in
 * Polish. After the address: the delivery method when the order has one, and the tracking number and its link only
 * when staff wrote a number (the link only with a template of the method).
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
        $shipment = [
            ...(null === $order->delivery ? [] : ['Sposób dostawy: '.$order->delivery->methodName]),
            ...(null === $order->shipment ? [] : ['Numer przesyłki: '.$order->shipment->trackingNumber]),
            ...(null === $order->shipment?->trackingUrl ? [] : ['Śledzenie przesyłki: '.$order->shipment->trackingUrl]),
        ];
        if ([] !== $shipment) {
            $lines = [...$lines, '', ...$shipment];
        }

        return implode("\n", $lines)."\n";
    }
}
