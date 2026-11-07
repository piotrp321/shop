<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderLine;

/**
 * An order as the customer gets it after placing it and in the emails about it. Amounts are in grosze. The access
 * token goes only to the guest who placed the order (null for a customer); the emails never show it.
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
                    $line->lineGross()->amount(),
                ),
                $order->lines(),
            ),
            new OrderTotalsView($totals->net()->amount(), $totals->vat()->amount(), $totals->gross()->amount(), $totals->currency()->value),
            $order->placedAt(),
            $order->paymentDeadline(),
            $order->accessToken()?->toString(),
        );
    }
}
