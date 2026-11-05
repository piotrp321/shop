<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * Inventory could not reserve every line of the order. Carries no number of units.
 */
final class InsufficientStockForOrder extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/insufficient-stock-for-order';

    public function __construct()
    {
        parent::__construct('Nie mamy już tylu sztuk części produktów. Sprawdź koszyk.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
