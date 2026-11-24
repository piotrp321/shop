<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * A variant with physical or reserved stock cannot be removed from its product (decision K5). The detail names the
 * first such variant by its stored values as a person reads them (IdentityText).
 */
final class VariantInUse extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/variant-in-use';

    /**
     * @param list<string> $storedValues the stored values of the variant
     */
    public function __construct(array $storedValues)
    {
        parent::__construct(\sprintf('%s ma stan magazynowy albo rezerwacje. Najpierw skoryguj stan do zera.', IdentityText::variant($storedValues)));
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
