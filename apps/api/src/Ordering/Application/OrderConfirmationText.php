<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Application\View\OrderLineView;
use App\Ordering\Application\View\OrderView;

/**
 * The subject and the plain text of the order confirmation email, in Polish (MailFormat). The moment of placement
 * and the payment deadline are shown in Warsaw time. The items have their values before the discount of the code; an
 * order with a code has the line „Kod <KOD>: −X zł” after them, and the total is after the discount (decision D5).
 */
final readonly class OrderConfirmationText
{
    public function subject(OrderView $order): string
    {
        return 'Potwierdzenie zamówienia '.$order->number;
    }

    public function body(OrderView $order): string
    {
        $placedAt = MailFormat::inWarsaw($order->placedAt);
        $deadline = MailFormat::inWarsaw($order->paymentDeadline);
        $address = $order->shippingAddress;

        $lines = [
            'Dzień dobry '.$order->fullName.',',
            '',
            \sprintf('dziękujemy za zamówienie %s złożone %s.', $order->number, $placedAt->format('d.m.Y \o H:i')),
            '',
            'Zamówione pozycje:',
            ...array_map(self::line(...), $order->lines),
            ...(null === $order->discount ? [] : [\sprintf('Kod %s: −%s', $order->discount->code, MailFormat::amount($order->discount->amountGross))]),
            '',
            'Suma brutto: '.MailFormat::amount($order->totals->gross),
            '',
            'Adres dostawy:',
            $order->fullName,
            $address->street,
            $address->postalCode.' '.$address->city,
            'Telefon: '.$order->phone,
            '',
            \sprintf('Zamówienie czeka na płatność do %s. Bez płatności anulujemy je automatycznie.', $deadline->format('H:i (d.m.Y)')),
        ];

        return implode("\n", $lines)."\n";
    }

    private static function line(OrderLineView $line): string
    {
        $name = '' === $line->variantLabel ? $line->productName : $line->productName.', '.$line->variantLabel;

        return \sprintf('- %s × %d: %s', $name, $line->quantity, MailFormat::amount($line->grossBeforeDiscount));
    }
}
