<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

/**
 * Deletes a planned promotion, based on the version the staff member saw.
 */
final readonly class DeletePromotion
{
    public function __construct(public string $id, public int $expectedVersion)
    {
    }
}
