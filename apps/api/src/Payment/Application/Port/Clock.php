<?php

declare(strict_types=1);

namespace App\Payment\Application\Port;

interface Clock
{
    public function now(): \DateTimeImmutable;
}
