<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The transition of staff is not in the table of the current status of the order (OrderStatus::allowedStaffTransitions()).
 */
final class OrderTransitionNotAllowed extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/order-transition-not-allowed';

    public function __construct()
    {
        parent::__construct('Tej zmiany nie można wykonać w obecnym statusie zamówienia.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
