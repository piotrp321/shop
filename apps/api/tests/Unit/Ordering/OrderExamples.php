<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Domain\Contact;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderLine;
use App\Ordering\Domain\OrderNumber;
use App\Ordering\Domain\OrderOwner;
use App\Ordering\Domain\PriceCopy;
use App\Ordering\Domain\ProductId;
use App\Ordering\Domain\RequestFingerprint;
use App\Ordering\Domain\ShippingAddress;
use App\Ordering\Domain\VariantId;

final class OrderExamples
{
    public const string ORDER = '0192f0c4-7c1a-7c4e-9a6e-0000000000aa';

    /**
     * A placed order of two lines (one with a variant label) that has no confirmation marker.
     */
    public static function placed(): Order
    {
        $address = ShippingAddress::inPoland('Długa 1', '00-950', 'Warszawa');
        $contact = Contact::of(EmailAddress::fromString('jan@example.com'), 'Jan Kowalski', '600100200');
        $price = static fn (int $gross): PriceCopy => PriceCopy::of($gross, 0, $gross, 0, 'zero', Currency::PLN);
        $product = ProductId::fromString('c0ffee00-7c1a-7c4e-9a6e-000000000042');

        $order = Order::place(
            OrderId::fromString(self::ORDER),
            OrderNumber::fromSequence(100001),
            OrderOwner::guest(),
            $contact,
            $address,
            [
                OrderLine::of(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-000000000001'), $product, 'Buty Asfalt', '42', 2, $price(12_300)),
                OrderLine::of(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-000000000002'), $product, 'Czapka', '', 1, $price(2_000)),
            ],
            IdempotencyKey::fromString('key-1'),
            RequestFingerprint::of('guest:cart', $contact, $address, 26_600),
            new \DateTimeImmutable('2026-11-05 12:00:00+00:00'),
        );
        $order->releaseEvents();

        return $order;
    }
}
