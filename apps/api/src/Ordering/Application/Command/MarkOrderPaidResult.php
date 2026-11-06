<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

/**
 * What MarkOrderPaid did. Only Paid changed the order; with the others the money does not pay it (the payment is to
 * be refunded by hand, except AlreadyPaid, the same payment reported again).
 */
enum MarkOrderPaidResult
{
    case Paid;
    case AlreadyPaid;
    case PaidByAnotherPayment;
    case PaidAfterDeadline;
    case OrderCancelled;
}
