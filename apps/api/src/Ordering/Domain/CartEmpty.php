<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The cart an order is placed from has no line.
 */
final class CartEmpty extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/cart-empty';

    public function __construct()
    {
        parent::__construct('Koszyk jest pusty.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
