<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The product changed since the version the change is based on.
 */
final class ProductModifiedConcurrently extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/product-modified';

    public function __construct()
    {
        parent::__construct('Produkt został w międzyczasie zmieniony. Odśwież go i spróbuj ponownie.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
