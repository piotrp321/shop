<?php

declare(strict_types=1);

namespace App\Payment\Application;

/**
 * What a provider answered to the cancellation of a session: cancelled (also when it was before), or refused because
 * the payment was already executed or already rejected.
 */
enum SessionCancellation
{
    case Cancelled;
    case AlreadyPaid;
    case AlreadyRejected;
}
