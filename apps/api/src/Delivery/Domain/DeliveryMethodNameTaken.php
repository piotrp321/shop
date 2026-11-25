<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * Another method has the same name without regard to case, also an inactive one (A9).
 */
final class DeliveryMethodNameTaken extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/delivery-method-name-taken';

    public function __construct()
    {
        parent::__construct('Metoda dostawy o tej nazwie już istnieje.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
