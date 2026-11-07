<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Domain\AccessToken;
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
    /** The access token of the guest order of placed(): 43 characters of base64url. */
    public const string TOKEN = 'dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM';
    public const string CUSTOMER = 'kc-sub-1';
    /** The gross total of placed(): 2 × 12 300 + 2 000. */
    public const int TOTAL = 26_600;
    public const string PLACED_AT = '2026-11-05 12:00:00+00:00';
    /** PLACED_AT + 30 minutes. */
    public const string DEADLINE = '2026-11-05 12:30:00+00:00';

    /**
     * A placed order of two lines (one with a variant label) that has no confirmation marker: of a guest with the
     * TOKEN, or of the CUSTOMER.
     */
    public static function placed(bool $byCustomer = false): Order
    {
        $address = ShippingAddress::inPoland('Długa 1', '00-950', 'Warszawa');
        $contact = Contact::of(EmailAddress::fromString('jan@example.com'), 'Jan Kowalski', '600100200');
        $price = static fn (int $gross): PriceCopy => PriceCopy::of($gross, 0, $gross, 0, 'zero', Currency::PLN);
        $product = ProductId::fromString('c0ffee00-7c1a-7c4e-9a6e-000000000042');

        $order = Order::place(
            OrderId::fromString(self::ORDER),
            OrderNumber::fromSequence(100001),
            $byCustomer ? OrderOwner::customer(self::CUSTOMER) : OrderOwner::guest(),
            $byCustomer ? null : AccessToken::fromString(self::TOKEN),
            $contact,
            $address,
            [
                OrderLine::of(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-000000000001'), $product, 'Buty Asfalt', '42', 2, $price(12_300)),
                OrderLine::of(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-000000000002'), $product, 'Czapka', '', 1, $price(2_000)),
            ],
            IdempotencyKey::fromString('key-1'),
            RequestFingerprint::of('guest:cart', $contact, $address, self::TOTAL),
            new \DateTimeImmutable(self::PLACED_AT),
        );
        $order->releaseEvents();

        return $order;
    }

    /**
     * A copy of the order with the same state at the given version, as a repository reads it back.
     */
    public static function copy(Order $order, ?int $version = null): Order
    {
        return Order::reconstitute(
            id: $order->id(),
            number: $order->number(),
            owner: $order->owner(),
            accessToken: $order->accessToken(),
            contact: $order->contact(),
            shippingAddress: $order->shippingAddress(),
            lines: $order->lines(),
            status: $order->status(),
            idempotencyKey: $order->idempotencyKey(),
            requestFingerprint: $order->requestFingerprint(),
            placedAt: $order->placedAt(),
            paymentDeadline: $order->paymentDeadline(),
            paidAt: $order->paidAt(),
            paymentId: $order->paymentId(),
            cancelledAt: $order->cancelledAt(),
            cancellationReason: $order->cancellationReason(),
            version: $version ?? $order->version(),
        );
    }
}
