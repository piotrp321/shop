<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * A method would become one active method too many (P16, DeliveryMethod::MAX_ACTIVE).
 */
final class DeliveryMethodLimitReached extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/delivery-method-limit-reached';

    public function __construct()
    {
        parent::__construct(\sprintf('Aktywnych metod dostawy może być najwyżej %d. Wyłącz inną metodę.', DeliveryMethod::MAX_ACTIVE));
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
