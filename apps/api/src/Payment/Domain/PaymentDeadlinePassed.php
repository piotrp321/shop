<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The order is cancelled, or new past its payment deadline.
 */
final class PaymentDeadlinePassed extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/payment-deadline-passed';

    public function __construct()
    {
        parent::__construct('Minął termin płatności za to zamówienie.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
