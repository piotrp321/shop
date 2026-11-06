<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The order is paid already.
 */
final class OrderAlreadyPaid extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/order-already-paid';

    public function __construct()
    {
        parent::__construct('Zamówienie jest już opłacone.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
