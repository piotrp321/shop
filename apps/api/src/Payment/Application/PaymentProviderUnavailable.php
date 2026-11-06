<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Shared\Domain\Exception\Unavailable;

/**
 * A provider did not answer properly: a network error, its 5 s were over, an unexpected status or an answer that
 * cannot be read. A 503 without detail; the message (for the log) never contains a secret.
 */
final class PaymentProviderUnavailable extends \RuntimeException implements Unavailable
{
}
