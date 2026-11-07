<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Shared\Domain\Exception\Conflict;

/**
 * The promotion is not at the version the change is based on.
 */
final class PromotionModifiedConcurrently extends \DomainException implements Conflict
{
    public function __construct()
    {
        parent::__construct('Promocja została w międzyczasie zmieniona. Odśwież ją i spróbuj ponownie.');
    }
}
