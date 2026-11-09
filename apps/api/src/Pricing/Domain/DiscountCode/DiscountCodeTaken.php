<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * Another code has the same text (decision Q4: unique for ever, also among disabled and expired codes).
 */
final class DiscountCodeTaken extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/discount-code-taken';

    public function __construct()
    {
        parent::__construct('Taki kod już istnieje.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
