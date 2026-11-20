<?php

declare(strict_types=1);

namespace App\Payment\Application\Command;

/**
 * What CloseAbandonedPayment did.
 */
enum AbandonedPaymentOutcome
{
    /** The session was cancelled at the provider and the payment is cancelled. */
    case Cancelled;
    /** The provider had rejected the payment; it is failed. */
    case Failed;
    /** The provider had executed the payment; it stays pending for its webhook. */
    case PaidAtProvider;
    /** The chosen payment is no longer the pending one of its order (a webhook or a new start came first): nothing done. */
    case NoLongerPending;
}
