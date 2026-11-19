<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The cart of a guest has more lines or more units of a line than a guest order may have (GuestOrderSize).
 */
final class GuestOrderTooLarge extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/guest-order-too-large';

    public function __construct()
    {
        parent::__construct(\sprintf(
            'Zamówienie bez konta może mieć najwyżej %d różnych produktów, po %d sztuk każdego. Zmniejsz ilości w koszyku.',
            GuestOrderSize::MAX_LINES,
            GuestOrderSize::MAX_QUANTITY,
        ));
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
