<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * A line of the cart has no current price or a variant Catalog does not know (decision P1: the whole cart or nothing).
 */
final class CartNotOrderable extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/cart-not-orderable';

    public function __construct()
    {
        parent::__construct('Część pozycji w koszyku nie może zostać kupiona. Sprawdź koszyk.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
