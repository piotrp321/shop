<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health\Probe;

final class GarageProbe extends HttpProbe
{
    public function name(): string
    {
        return 'garage';
    }

    protected function path(): string
    {
        return '/health';
    }
}
