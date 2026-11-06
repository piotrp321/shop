<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The Idempotency-Key was used by an order of another request (another cart, customer or content). Carries no data of that order.
 */
final class IdempotencyKeyReused extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/idempotency-key-reused';

    public function __construct()
    {
        parent::__construct('Ten klucz żądania był już użyty dla innego zamówienia.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
