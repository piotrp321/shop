<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * What a payment did to an order (Order::markPaid()). Only Paid changes the order; the others tell the caller that
 * the money it holds does not pay this order.
 */
enum PaymentResult
{
    /** The order is paid now. */
    case Paid;
    /** The same payment paid it before. */
    case AlreadyPaid;
    /** Another payment paid it before. */
    case PaidByAnotherPayment;
    /** The provider executed the payment after the deadline; the order stays new and will be cancelled. */
    case PaidAfterDeadline;
    /** The order was cancelled; it is never paid. */
    case OrderCancelled;
}
