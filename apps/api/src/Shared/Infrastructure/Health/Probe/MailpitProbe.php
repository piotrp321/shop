<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health\Probe;

final class MailpitProbe extends HttpProbe
{
    public function name(): string
    {
        return 'mailpit';
    }

    protected function path(): string
    {
        return '/readyz';
    }
}
