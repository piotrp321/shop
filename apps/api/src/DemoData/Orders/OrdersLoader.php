<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Copy\CopyWriter;
use App\DemoData\Profile\RunContext;
use Doctrine\DBAL\Connection;

/**
 * Step 5 of the generator: the orders with their lines, their history, the payments of the paid ones and the usages of
 * the historical codes, written with COPY part by part (no aggregates, no events, no scheduled messages, no e-mails:
 * nothing is in progress, P8). The catalog and the prices are read from the tables of steps 1 and 3.
 *
 * Tables written: ordering_order, ordering_order_line, ordering_order_status_change, payment_payment,
 * pricing_discount_code_usage.
 */
final readonly class OrdersLoader
{
    private const array ORDER_COLUMNS = [
        'id', 'number', 'status', 'owner_type', 'customer_id', 'email', 'full_name', 'phone', 'street', 'postal_code', 'city', 'country', 'currency',
        'total_net', 'total_vat', 'total_gross', 'idempotency_key', 'request_fingerprint', 'placed_at', 'version', 'payment_deadline', 'access_token',
        'paid_at', 'payment_id', 'cancelled_at', 'cancellation_reason', 'shipped_at', 'delivered_at', 'returned_at', 'refund_amount', 'refund_due_at',
        'refund_completed_at', 'refund_completed_by_staff_id', 'refund_completed_by_username', 'refund_note', 'discount_code', 'discount_kind',
        'discount_value', 'discount_gross',
    ];
    private const array LINE_COLUMNS = [
        'id', 'order_id', 'position', 'variant_id', 'product_id', 'product_name', 'variant_label', 'quantity', 'unit_net', 'unit_vat', 'unit_gross',
        'vat_rate_bp', 'tax_class', 'line_net', 'line_vat', 'line_gross', 'discount_gross', 'promotion_id', 'promotion_name', 'promotion_percent_off',
        'regular_unit_gross',
    ];

    public function __construct(
        private Connection $connection,
        private CopyWriter $copy,
        private Dictionaries $dictionaries,
        private PersonDictionaries $people,
    ) {
    }

    /**
     * Writes the orders of the parts `worker`, `worker + workers`, …, each in a transaction of its own.
     *
     * @param \Closure(int, int, int): void|null $progress called after each part with the part, its orders and its lines
     *
     * @return array{parts: int, orders: int, lines: int}
     *
     * @throws \RuntimeException when the catalog or the prices of the orders are not in the tables
     */
    public function load(RunContext $context, int $worker, int $workers, ?\Closure $progress = null): array
    {
        $generator = OrderGenerator::forRun($this->dictionaries, $this->people, $context);
        $catalog = new DbalOrderCatalog($this->connection, $context->namespace);
        $totals = ['parts' => 0, 'orders' => 0, 'lines' => 0];
        for ($part = $worker; $part < $generator->parts(); $part += $workers) {
            [$orders, $lines] = $this->connection->transactional(fn (): array => $this->loadPart($generator->part($part, $catalog)));
            ++$totals['parts'];
            $totals['orders'] += $orders;
            $totals['lines'] += $lines;
            if (null !== $progress) {
                $progress($part, $orders, $lines);
            }
        }

        return $totals;
    }

    /**
     * @param list<OrderSpec> $orders
     *
     * @return array{int, int} the orders and the lines written
     */
    private function loadPart(array $orders): array
    {
        $this->copy->relaxDurability();
        $written = $this->copy->copy('ordering_order', self::ORDER_COLUMNS, $this->orderRows($orders));
        $lines = $this->copy->copy('ordering_order_line', self::LINE_COLUMNS, $this->lineRows($orders));
        $this->copy->copy(
            'ordering_order_status_change',
            ['order_id', 'position', 'from_status', 'to_status', 'occurred_at', 'actor_type', 'staff_id', 'staff_username', 'note'],
            $this->historyRows($orders),
        );
        $this->copy->copy(
            'payment_payment',
            ['id', 'order_id', 'provider', 'amount', 'currency', 'status', 'provider_payment_id', 'redirect_url', 'idempotency_key', 'request_fingerprint', 'created_at', 'completed_at', 'executed_at', 'refund_required_reason'],
            $this->paymentRows($orders),
        );
        $this->copy->copy('pricing_discount_code_usage', ['id', 'code_id', 'order_id', 'used_at', 'released_at'], $this->usageRows($orders));

        return [$written, $lines];
    }

    /**
     * @param list<OrderSpec> $orders
     *
     * @return \Generator<int, list<mixed>>
     */
    private function orderRows(array $orders): \Generator
    {
        foreach ($orders as $o) {
            yield [
                $o->id, $o->number, $o->status, $o->ownerType, $o->customerId, $o->email, $o->fullName, $o->phone, $o->street, $o->postalCode, $o->city, 'PL', 'PLN',
                $o->totalNet, $o->totalVat, $o->totalGross, $o->idempotencyKey, $o->requestFingerprint, $o->placedAt, $o->version, $o->paymentDeadline, $o->accessToken,
                $o->paidAt, $o->paymentId, $o->cancelledAt, $o->cancellationReason, $o->shippedAt, $o->deliveredAt, $o->returnedAt, $o->refundAmount, $o->refundDueAt,
                $o->refundCompletedAt, $o->refundCompletedById, $o->refundCompletedByUsername, null, $o->discountCode, $o->discountKind,
                $o->discountValue, $o->discountGross,
            ];
        }
    }

    /**
     * @param list<OrderSpec> $orders
     *
     * @return \Generator<int, list<mixed>>
     */
    private function lineRows(array $orders): \Generator
    {
        foreach ($orders as $order) {
            foreach ($order->lines as $l) {
                yield [
                    $l->id, $order->id, $l->position, $l->variantId, $l->productId, $l->productName, $l->variantLabel, $l->quantity, $l->unitNet, $l->unitVat, $l->unitGross,
                    $l->vatRateBp, $l->taxClass, $l->lineNet, $l->lineVat, $l->lineGross, $l->discountGross, $l->promotionId, $l->promotionName, $l->promotionPercentOff,
                    $l->regularUnitGross,
                ];
            }
        }
    }

    /**
     * @param list<OrderSpec> $orders
     *
     * @return \Generator<int, list<mixed>>
     */
    private function historyRows(array $orders): \Generator
    {
        foreach ($orders as $order) {
            foreach ($order->history as $change) {
                yield [$order->id, $change->position, $change->from, $change->to, $change->occurredAt, $change->actorType, $change->staffId, $change->staffUsername, null];
            }
        }
    }

    /**
     * @param list<OrderSpec> $orders
     *
     * @return \Generator<int, list<mixed>>
     */
    private function paymentRows(array $orders): \Generator
    {
        foreach ($orders as $order) {
            $payment = $order->payment;
            if (null !== $payment) {
                yield [$payment->id, $order->id, $payment->provider, $payment->amount, 'PLN', 'succeeded', null, null, $payment->idempotencyKey, $payment->requestFingerprint, $payment->createdAt, $payment->executedAt, $payment->executedAt, null];
            }
        }
    }

    /**
     * @param list<OrderSpec> $orders
     *
     * @return \Generator<int, list<mixed>>
     */
    private function usageRows(array $orders): \Generator
    {
        foreach ($orders as $order) {
            $usage = $order->codeUsage;
            if (null !== $usage) {
                yield [$usage->id, $usage->codeId, $order->id, $usage->usedAt, $usage->releasedAt];
            }
        }
    }
}
