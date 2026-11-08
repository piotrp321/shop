<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The order has no refund to complete: none was created, or it is completed already.
 */
final class RefundNotPending extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/refund-not-pending';

    public function __construct()
    {
        parent::__construct('To zamówienie nie ma zwrotu do wykonania.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
