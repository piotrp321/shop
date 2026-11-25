<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * After the first publication a variant keeps its meaning (decision K5): the values of a kept variant and the names
 * of the axes change only without a change of their comparison key (but the later of two stored names with one
 * identity, which takes a new name), and axes cannot be added, removed or reordered.
 * The detail names the stored values of the variant or the stored name of the axis as a person reads them, without
 * forbidden or trimmed characters (IdentityText), and says what the correction of a stored text with forbidden
 * characters may be.
 */
final class VariantIdentityLocked extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/variant-locked';

    /**
     * @param list<string> $storedValues the stored values of the variant
     * @param list<string> $givenValues  the values given in their place; the correction of forbidden characters is
     *                                   named only when a value that did not keep its text has them
     */
    public static function variant(array $storedValues, array $givenValues): self
    {
        $correction = array_any(IdentityText::changed($storedValues, $givenValues), IdentityText::isRefused(...))
            ? 'Zapisana wartość ma znaki niedozwolone: usuń je albo zastąp odstępem i popraw tylko wielkość liter, albo dodaj nowy wariant i usuń stary.'
            : 'Popraw tylko wielkość liter albo dodaj nowy wariant i usuń stary.';

        return new self(\sprintf('%s nie może zmienić wartości po pierwszej publikacji. %s', IdentityText::variant($storedValues), $correction));
    }

    public static function axes(): self
    {
        return new self('Po pierwszej publikacji nie można dodać, usunąć ani przestawić osi wariantów.');
    }

    /**
     * @param bool $repeated whether the second axis repeats the stored name: then the second, not this one, takes a new
     *                       name
     */
    public static function axisName(string $storedName, bool $repeated = false): self
    {
        $correction = IdentityText::isRefused($storedName)
            ? 'Zapisana nazwa ma znaki niedozwolone: usuń je albo zastąp odstępem i popraw tylko wielkość liter.'
            : 'Popraw tylko wielkość liter.';
        if ($repeated) {
            $correction .= ' Nową nazwę może dostać tylko druga oś, która powtarza tę nazwę.';
        }

        return new self(\sprintf('Oś „%s” nie może zmienić nazwy po pierwszej publikacji. %s', IdentityText::shown($storedName), $correction));
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
