<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * A change no payment may have (a second session of the provider, a refund of a payment that did not succeed, an
 * empty or too long redirect address). A logic error: the handlers never ask for it.
 */
final class InvalidPayment extends \LogicException
{
}
