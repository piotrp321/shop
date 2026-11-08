<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The catalog price of a variant in an active promotion does not change (decision Q13): the promotion and the
 * Omnibus price rest on it. Ending the promotion unlocks it.
 */
final class PriceLockedByPromotion extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/price-locked-by-promotion';

    public function __construct()
    {
        parent::__construct('Cena wariantu w aktywnej promocji nie może się zmienić. Zakończ promocję, żeby zmienić cenę.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
