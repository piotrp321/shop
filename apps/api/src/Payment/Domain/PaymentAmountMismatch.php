<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The provider reports another amount than the payment was started with.
 */
final class PaymentAmountMismatch extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('Kwota płatności nie zgadza się z kwotą zamówienia.');
    }
}
