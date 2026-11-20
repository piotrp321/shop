<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Domain\AccessToken;
use App\Ordering\Domain\Contact;
use App\Ordering\Domain\Currency;
use App\Ordering\Domain\EmailAddress;
use App\Ordering\Domain\IdempotencyKey;
use App\Ordering\Domain\LineAmounts;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderDelivery;
use App\Ordering\Domain\OrderDeliveryShare;
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
    /** The delivery method of placed(). */
    public const string DELIVERY_METHOD = '0192f0c4-7c1a-7c4e-9a6e-0000000000d0';
    /** The access token of the guest order of placed(): 43 characters of base64url. */
    public const string TOKEN = 'dGhpcy1pcy1hLXRva2VuLW9mLXRoZS1ndWVzdC0xMjM';
    public const string CUSTOMER = 'kc-sub-1';
    /** The gross total of placed(): 2 × 12 300 + 2 000, delivered for free. */
    public const int TOTAL = 26_600;
    /** The cost of paidDelivery(). */
    public const int DELIVERY_COST = 1_999;
    public const string PLACED_AT = '2026-11-05 12:00:00+00:00';
    /** PLACED_AT + 30 minutes. */
    public const string DEADLINE = '2026-11-05 12:30:00+00:00';

    /**
     * A placed order of two lines (one with a variant label) that has no confirmation marker, with a free delivery
     * (so its total is the one of its lines) unless another is given: of a guest with the TOKEN, or of the CUSTOMER.
     */
    public static function placed(bool $byCustomer = false, ?OrderDelivery $delivery = null): Order
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
                OrderLine::of(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-000000000001'), $product, 'Buty Asfalt', '42', 2, $price(12_300), self::undiscounted($price(12_300), 2), null),
                OrderLine::of(VariantId::fromString('0192f0c4-7c1a-7c4e-9a6e-000000000002'), $product, 'Czapka', '', 1, $price(2_000), self::undiscounted($price(2_000), 1), null),
            ],
            null,
            $delivery ?? self::freeDelivery(),
            IdempotencyKey::fromString('key-1'),
            RequestFingerprint::of('guest:cart', $contact, $address, self::TOTAL),
            new \DateTimeImmutable(self::PLACED_AT),
        );
        $order->releaseEvents();

        return $order;
    }

    /**
     * A delivery by DELIVERY_METHOD that costs nothing (no share of VAT).
     */
    public static function freeDelivery(): OrderDelivery
    {
        return OrderDelivery::of(self::DELIVERY_METHOD, 'Kurier standardowy', 'https://tracking.example.com/{numer}', 0, [], Currency::PLN);
    }

    /**
     * A delivery by DELIVERY_METHOD of DELIVERY_COST, all of it at the rate of the lines of placed() (0 %).
     */
    public static function paidDelivery(): OrderDelivery
    {
        return OrderDelivery::of(self::DELIVERY_METHOD, 'Kurier standardowy', 'https://tracking.example.com/{numer}', self::DELIVERY_COST, [OrderDeliveryShare::of(0, self::DELIVERY_COST, self::DELIVERY_COST, 0, Currency::PLN)], Currency::PLN);
    }

    /**
     * The values of a line without a discount: the unit price times the quantity (net + vat = gross holds for them).
     */
    public static function undiscounted(PriceCopy $price, int $quantity): LineAmounts
    {
        return LineAmounts::of(0, $price->net()->amount() * $quantity, $price->vat()->amount() * $quantity, $price->gross()->amount() * $quantity, $price->currency());
    }

    /**
     * A copy of the order with the same state at the given version, as a repository reads it back; without its
     * delivery, as an order placed before the delivery is read.
     */
    public static function copy(Order $order, ?int $version = null, bool $withoutDelivery = false): Order
    {
        return Order::reconstitute(
            id: $order->id(),
            number: $order->number(),
            owner: $order->owner(),
            accessToken: $order->accessToken(),
            contact: $order->contact(),
            shippingAddress: $order->shippingAddress(),
            lines: $order->lines(),
            discount: $order->discount(),
            delivery: $withoutDelivery ? null : $order->delivery(),
            status: $order->status(),
            idempotencyKey: $order->idempotencyKey(),
            requestFingerprint: $order->requestFingerprint(),
            placedAt: $order->placedAt(),
            paymentDeadline: $order->paymentDeadline(),
            paidAt: $order->paidAt(),
            paymentId: $order->paymentId(),
            cancelledAt: $order->cancelledAt(),
            cancellationReason: $order->cancellationReason(),
            shippedAt: $order->shippedAt(),
            deliveredAt: $order->deliveredAt(),
            returnedAt: $order->returnedAt(),
            refund: $order->refund(),
            history: $order->history(),
            version: $version ?? $order->version(),
        );
    }
}
