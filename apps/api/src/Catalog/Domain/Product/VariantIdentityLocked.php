<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * After the first publication a variant keeps its meaning (decision K5): the values of a kept variant and the names
 * of the axes change only without a change of their comparison key, and axes cannot be added, removed or reordered.
 * The detail names the stored label of the variant or the stored name of the axis.
 */
final class VariantIdentityLocked extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/variant-locked';

    public static function variant(string $label): self
    {
        return new self(\sprintf(
            '%s nie może zmienić wartości po pierwszej publikacji. Popraw tylko wielkość liter albo dodaj nowy wariant i usuń stary.',
            '' === $label ? 'Wariant domyślny' : \sprintf('Wariant „%s”', $label),
        ));
    }

    public static function axes(): self
    {
        return new self('Po pierwszej publikacji nie można dodać, usunąć ani przestawić osi wariantów.');
    }

    public static function axisName(string $name): self
    {
        return new self(\sprintf('Oś „%s” nie może zmienić nazwy po pierwszej publikacji. Popraw tylko wielkość liter.', $name));
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
