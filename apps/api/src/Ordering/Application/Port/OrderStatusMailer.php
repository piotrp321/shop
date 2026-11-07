<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\View\OrderView;

/**
 * The emails about a change of the status of an order, to its email address. Each returns once the mail transport
 * has accepted the mail; a failure of the transport is thrown, so the caller can retry.
 */
interface OrderStatusMailer
{
    /**
     * @throws \Throwable the mail transport refused or could not be reached
     */
    public function sendPaymentReceived(OrderView $order): void;

    /**
     * The cancellation for want of a payment.
     *
     * @throws \Throwable the mail transport refused or could not be reached
     */
    public function sendOrderCancelled(OrderView $order): void;

    /**
     * The cancellation of a paid order by staff.
     *
     * @throws \Throwable the mail transport refused or could not be reached
     */
    public function sendOrderCancelledByStaff(OrderView $order): void;

    /**
     * @throws \Throwable the mail transport refused or could not be reached
     */
    public function sendOrderShipped(OrderView $order): void;

    /**
     * @throws \Throwable the mail transport refused or could not be reached
     */
    public function sendReturnReceived(OrderView $order): void;
}
