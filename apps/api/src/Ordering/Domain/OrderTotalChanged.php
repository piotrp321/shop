<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The gross total computed now differs from the one the customer saw (decision P2), higher or lower.
 */
final class OrderTotalChanged extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/order-total-changed';

    public function __construct()
    {
        parent::__construct('Kwota zamówienia się zmieniła. Sprawdź podsumowanie.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
