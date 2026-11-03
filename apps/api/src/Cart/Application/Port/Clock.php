<?php

declare(strict_types=1);

namespace App\Cart\Application\Port;

/**
 * The current time, which decides the expiry of carts.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
