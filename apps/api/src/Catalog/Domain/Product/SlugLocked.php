<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The slug of a product is fixed from its first publication on, also after an unpublication (decision K4).
 */
final class SlugLocked extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/slug-locked';

    public function __construct()
    {
        parent::__construct('Slug produktu nie może się zmienić po pierwszej publikacji.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
