<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
