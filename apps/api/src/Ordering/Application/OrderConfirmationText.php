<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Application\View\OrderLineView;
use App\Ordering\Application\View\OrderView;

/**
 * The subject and the plain text of the order confirmation email, in Polish. Amounts are written as `1 234,56 zł`
 * with plain spaces (a plain-text email has no markup to keep the number together, and a no-break space may not
 * survive a mail client). The moment of placement is shown in Warsaw time.
 */
final readonly class OrderConfirmationText
{
    private const string TIME_ZONE = 'Europe/Warsaw';

    public function subject(OrderView $order): string
    {
        return 'Potwierdzenie zamówienia '.$order->number;
    }

    public function body(OrderView $order): string
    {
        $placedAt = $order->placedAt->setTimezone(new \DateTimeZone(self::TIME_ZONE));
        $address = $order->shippingAddress;

        $lines = [
            'Dzień dobry '.$order->fullName.',',
            '',
            \sprintf('dziękujemy za zamówienie %s złożone %s.', $order->number, $placedAt->format('d.m.Y \o H:i')),
            '',
            'Zamówione pozycje:',
            ...array_map(self::line(...), $order->lines),
            '',
            'Suma brutto: '.self::amount($order->totals->gross),
            '',
            'Adres dostawy:',
            $order->fullName,
            $address->street,
            $address->postalCode.' '.$address->city,
            'Telefon: '.$order->phone,
            '',
            'O płatności poinformujemy w osobnej wiadomości.',
        ];

        return implode("\n", $lines)."\n";
    }

    private static function line(OrderLineView $line): string
    {
        $name = '' === $line->variantLabel ? $line->productName : $line->productName.', '.$line->variantLabel;

        return \sprintf('- %s × %d: %s', $name, $line->quantity, self::amount($line->lineGross));
    }

    private static function amount(int $grosze): string
    {
        return \sprintf('%s,%02d zł', number_format(intdiv($grosze, 100), 0, '', ' '), $grosze % 100);
    }
}
