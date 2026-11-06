<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The Idempotency-Key was used by a payment of another request (another order or provider). Carries no data of that payment.
 */
final class PaymentIdempotencyKeyReused extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/payment-idempotency-key-reused';

    public function __construct()
    {
        parent::__construct('Ten klucz żądania był już użyty dla innej płatności.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
