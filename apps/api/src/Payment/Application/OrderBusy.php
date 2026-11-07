<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Shared\Domain\Exception\Unavailable;

/**
 * The order changed while a webhook paid it (e.g. its confirmation email stored its marker, or it was cancelled):
 * everything the webhook did is rolled back and the provider retries. A 503 without detail.
 */
final class OrderBusy extends \RuntimeException implements Unavailable
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The order changed while the webhook paid it.', previous: $previous);
    }
}
