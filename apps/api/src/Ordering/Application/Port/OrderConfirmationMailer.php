<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

use App\Ordering\Application\View\OrderView;

interface OrderConfirmationMailer
{
    /**
     * Sends the confirmation of the order to its email address and returns once the mail transport has accepted it.
     * A failure of the transport is thrown, so the caller can retry.
     *
     * @throws \Throwable the mail transport refused or could not be reached
     */
    public function send(OrderView $order): void;
}
