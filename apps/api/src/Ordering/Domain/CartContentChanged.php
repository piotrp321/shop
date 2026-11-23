<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The locked cart has other lines or quantities than the checkout showed (`expectedLines`), whatever its total.
 */
final class CartContentChanged extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/cart-changed';

    public function __construct()
    {
        parent::__construct('Zawartość koszyka się zmieniła. Sprawdź podsumowanie.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
