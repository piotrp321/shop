<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Shared\Domain\Exception\Conflict;

/**
 * Not every line of a reservation can be reserved, so none is: the reservation and its key are not stored, and the
 * same key may be used again later. Lists every short line.
 */
final class InsufficientStock extends \DomainException implements Conflict
{
    /**
     * @param non-empty-list<Shortage> $shortages ordered by product id
     */
    public function __construct(public readonly array $shortages)
    {
        parent::__construct(\sprintf('Not enough stock for %d of the reservation lines.', \count($shortages)));
    }
}
