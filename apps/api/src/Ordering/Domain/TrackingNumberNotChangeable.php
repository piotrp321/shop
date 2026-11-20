<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The tracking number of an order is changed only while the order is shipped or delivered (P18 of
 * docs/features/delivery/spec.md, W8a).
 */
final class TrackingNumberNotChangeable extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/tracking-number-not-changeable';

    public function __construct()
    {
        parent::__construct('Numer przesyłki można zmienić tylko w zamówieniu wysłanym albo dostarczonym.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
