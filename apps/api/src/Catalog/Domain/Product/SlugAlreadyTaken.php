<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

final class SlugAlreadyTaken extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/slug-taken';

    public function __construct()
    {
        parent::__construct('Ten slug ma już inny produkt. Podaj własny slug.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
