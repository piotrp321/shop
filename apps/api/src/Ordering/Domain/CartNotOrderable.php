<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;

/**
 * A line of the cart has no current price or a variant Catalog does not know (decision P1: the whole cart or nothing).
 */
final class CartNotOrderable extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Część pozycji w koszyku nie może zostać kupiona. Sprawdź koszyk.');
    }
}
