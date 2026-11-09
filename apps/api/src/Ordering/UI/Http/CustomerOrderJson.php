<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\CustomerOrderList;
use App\Ordering\Application\Query\CustomerOrderListItem;
use App\Ordering\Application\View\CustomerOrderView;

/**
 * The bodies of the order history endpoints of the store, in the schemas of nelmio_api_doc.yaml (default area):
 * CustomerOrder and CustomerOrderList. Moments in RFC 3339 and UTC.
 */
final class CustomerOrderJson
{
    /**
     * @return array<string, mixed>
     */
    public static function order(CustomerOrderView $order): array
    {
        return [
            'orderId' => $order->orderId,
            'number' => $order->number,
            'status' => $order->status,
            'shippingAddress' => [
                'street' => $order->shippingAddress->street,
                'postalCode' => $order->shippingAddress->postalCode,
                'city' => $order->shippingAddress->city,
                'country' => $order->shippingAddress->country,
            ],
            'lines' => array_map(OrderLineJson::line(...), $order->lines),
            'discount' => OrderLineJson::discount($order->discount),
            'totals' => [
                'net' => $order->totals->net,
                'vat' => $order->totals->vat,
                'gross' => $order->totals->gross,
                'currency' => $order->totals->currency,
            ],
            'placedAt' => self::utc($order->placedAt),
            'paymentDeadline' => self::utc($order->paymentDeadline),
            'paymentDeadlinePassed' => $order->paymentDeadlinePassed,
            'paidAt' => self::utc($order->paidAt),
            'shippedAt' => self::utc($order->shippedAt),
            'deliveredAt' => self::utc($order->deliveredAt),
            'cancelledAt' => self::utc($order->cancelledAt),
            'returnedAt' => self::utc($order->returnedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(CustomerOrderList $list): array
    {
        return [
            'items' => array_map(static fn (CustomerOrderListItem $item): array => [
                'orderId' => $item->orderId,
                'number' => $item->number,
                'placedAt' => self::utc($item->placedAt),
                'status' => $item->status,
                'totalGross' => $item->totalGross,
                'currency' => $item->currency,
                'itemCount' => $item->itemCount,
                'paymentDeadline' => self::utc($item->paymentDeadline),
                'paymentDeadlinePassed' => $item->paymentDeadlinePassed,
            ], $list->items),
            'nextCursor' => $list->nextCursor,
        ];
    }

    /**
     * @return ($moment is null ? null : string)
     */
    private static function utc(?\DateTimeImmutable $moment): ?string
    {
        return $moment?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
    }
}
