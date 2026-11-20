<?php

declare(strict_types=1);

namespace App\Delivery\Application\Port;

/**
 * The current time, for the moments a method is created and changed.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
