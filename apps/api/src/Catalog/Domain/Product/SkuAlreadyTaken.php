<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The unique index of the SKU refused the write: a variant of another product got the code between the check of the
 * handler and the write. Nothing was saved.
 */
final class SkuAlreadyTaken extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/sku-taken';

    public function __construct()
    {
        parent::__construct('Kod SKU jest już używany przez inny produkt.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
