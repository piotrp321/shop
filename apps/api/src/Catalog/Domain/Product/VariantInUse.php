<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * A variant with physical or reserved stock cannot be removed from its product (decision K5). The detail names the
 * label of the first such variant.
 */
final class VariantInUse extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/variant-in-use';

    public function __construct(string $label)
    {
        parent::__construct(\sprintf('%s ma stan magazynowy albo rezerwacje. Najpierw skoryguj stan do zera.', '' === $label ? 'Wariant domyślny' : \sprintf('Wariant „%s”', $label)));
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
