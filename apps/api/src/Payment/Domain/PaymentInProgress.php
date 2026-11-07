<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * Another payment of the order is under way: a concurrent start wrote its payment first, or the provider refused to
 * cancel the pending payment because it was executed (its webhook brings the confirmation). One type, two texts.
 */
final class PaymentInProgress extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/payment-in-progress';

    public static function concurrentStart(): self
    {
        return new self('Płatność za to zamówienie jest w toku. Spróbuj za chwilę.');
    }

    public static function alreadyExecuted(): self
    {
        return new self('Płatność została już wykonana. Czekamy na jej potwierdzenie.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
