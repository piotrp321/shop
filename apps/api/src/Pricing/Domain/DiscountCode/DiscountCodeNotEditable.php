<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The change is not allowed once the code has started (decision Q3): then only its end, the flag and an existing
 * limit change; its value, minimum and start are fixed, and a limit is not added.
 */
final class DiscountCodeNotEditable extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/discount-code-not-editable';

    public function __construct()
    {
        parent::__construct('Tej zmiany nie można wprowadzić w kodzie, który już obowiązuje albo był użyty.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
