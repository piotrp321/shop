<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * The chosen delivery method is unknown or no longer active (docs/features/delivery/spec.md, W5): nothing is ordered.
 */
final class DeliveryMethodUnavailable extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/delivery-method-unavailable';

    public function __construct()
    {
        parent::__construct('Wybrany sposób dostawy jest niedostępny. Wybierz inny.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
