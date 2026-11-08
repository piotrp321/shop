<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\Query\BackofficeOrderList;
use App\Ordering\Application\Query\BackofficeOrderListItem;
use App\Ordering\Application\View\BackofficeOrderView;
use App\Ordering\Application\View\OrderLineView;
use App\Ordering\Application\View\StatusChangeView;

/**
 * The bodies of the order endpoints of the panel, in the schemas of nelmio_api_doc.yaml (area `admin`):
 * BackofficeOrder and BackofficeOrderList. Moments in RFC 3339 and UTC.
 */
final class BackofficeOrderJson
{
    /**
     * @param list<string> $allowedTransitions the transitions of the status the staff member may make
     *
     * @return array<string, mixed>
     */
    public static function order(BackofficeOrderView $order, array $allowedTransitions, bool $canCompleteRefund): array
    {
        $refund = $order->refund;

        return [
            'orderId' => $order->orderId,
            'number' => $order->number,
            'status' => $order->status,
            'version' => $order->version,
            'email' => $order->email,
            'fullName' => $order->fullName,
            'phone' => $order->phone,
            'shippingAddress' => [
                'street' => $order->shippingAddress->street,
                'postalCode' => $order->shippingAddress->postalCode,
                'city' => $order->shippingAddress->city,
                'country' => $order->shippingAddress->country,
            ],
            'lines' => array_map(static fn (OrderLineView $line): array => [
                ...OrderLineJson::line($line),
                'promotion' => null === $line->promotion ? null : [
                    'name' => $line->promotion->name,
                    'percentOff' => $line->promotion->percentOff,
                    'regularUnitGross' => $line->promotion->regularUnitGross,
                ],
            ], $order->lines),
            'discount' => OrderLineJson::discount($order->discount),
            'totals' => [
                'net' => $order->totals->net,
                'vat' => $order->totals->vat,
                'gross' => $order->totals->gross,
                'currency' => $order->totals->currency,
            ],
            'placedAt' => self::utc($order->placedAt),
            'paymentDeadline' => self::utc($order->paymentDeadline),
            'paidAt' => self::utc($order->paidAt),
            'paymentId' => $order->paymentId,
            'shippedAt' => self::utc($order->shippedAt),
            'deliveredAt' => self::utc($order->deliveredAt),
            'cancelledAt' => self::utc($order->cancelledAt),
            'cancellationReason' => $order->cancellationReason,
            'returnedAt' => self::utc($order->returnedAt),
            'history' => array_map(static fn (StatusChangeView $change): array => [
                'from' => $change->from,
                'to' => $change->to,
                'occurredAt' => self::utc($change->occurredAt),
                'actorType' => $change->actorType,
                'staffId' => $change->staffId,
                'staffUsername' => $change->staffUsername,
                'note' => $change->note,
            ], $order->history),
            'refund' => null === $refund ? null : [
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'dueAt' => self::utc($refund->dueAt),
                'overdue' => $refund->overdue,
                'completedAt' => self::utc($refund->completedAt),
                'completedByStaffId' => $refund->completedByStaffId,
                'completedByUsername' => $refund->completedByUsername,
                'note' => $refund->note,
            ],
            'allowedTransitions' => $allowedTransitions,
            'canCompleteRefund' => $canCompleteRefund,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(BackofficeOrderList $list): array
    {
        return [
            'items' => array_map(static fn (BackofficeOrderListItem $item): array => [
                'orderId' => $item->orderId,
                'number' => $item->number,
                'placedAt' => self::utc($item->placedAt),
                'status' => $item->status,
                'email' => $item->email,
                'fullName' => $item->fullName,
                'totalGross' => $item->totalGross,
                'currency' => $item->currency,
                'itemCount' => $item->itemCount,
                'refund' => null === $item->refund ? null : [
                    'amount' => $item->refund->amount,
                    'dueAt' => self::utc($item->refund->dueAt),
                    'completedAt' => self::utc($item->refund->completedAt),
                    'overdue' => $item->refund->overdue,
                ],
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
