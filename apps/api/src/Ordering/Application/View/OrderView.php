<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderLine;

/**
 * An order as the customer gets it after placing it and in the emails about it. Amounts are in grosze; the totals are
 * after the discount of the code. The access token goes only to the guest who placed the order (null for a customer);
 * the emails never show it.
 */
final readonly class OrderView
{
    /**
     * @param list<OrderLineView> $lines in the order of the cart
     */
    public function __construct(
        public string $orderId,
        public string $number,
        public string $status,
        public string $email,
        public string $fullName,
        public string $phone,
        public ShippingAddressView $shippingAddress,
        public array $lines,
        public ?OrderDiscountView $discount,
        public OrderTotalsView $totals,
        public \DateTimeImmutable $placedAt,
        public \DateTimeImmutable $paymentDeadline,
        public ?string $accessToken,
    ) {
    }

    public static function of(Order $order): self
    {
        $contact = $order->contact();
        $address = $order->shippingAddress();
        $totals = $order->totals();
        $discount = $order->discount();

        return new self(
            $order->id()->toString(),
            $order->number()->toString(),
            $order->status()->value,
            $contact->email()->toString(),
            $contact->fullName(),
            $contact->phone(),
            new ShippingAddressView($address->street(), $address->postalCode(), $address->city(), $address->country()),
            array_map(
                static fn (OrderLine $line): OrderLineView => new OrderLineView(
                    $line->variantId()->toString(),
                    $line->productName(),
                    $line->variantLabel(),
                    $line->quantity(),
                    $line->unitPrice()->gross()->amount(),
                    $line->grossBeforeDiscount()->amount(),
                    $line->discount()->amount(),
                    $line->lineGross()->amount(),
                    null === $line->promotion() ? null : new OrderLinePromotionView($line->promotion()->name(), $line->promotion()->percentOff(), $line->promotion()->regularUnitGross()->amount()),
                ),
                $order->lines(),
            ),
            null === $discount ? null : new OrderDiscountView($discount->code(), $discount->kind()->value, $discount->value(), $discount->amount()->amount()),
            new OrderTotalsView($totals->net()->amount(), $totals->vat()->amount(), $totals->gross()->amount(), $totals->currency()->value),
            $order->placedAt(),
            $order->paymentDeadline(),
            $order->accessToken()?->toString(),
        );
    }
}
