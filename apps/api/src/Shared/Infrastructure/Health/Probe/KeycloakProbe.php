<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health\Probe;

final class KeycloakProbe extends HttpProbe
{
    public function name(): string
    {
        return 'keycloak';
    }

    protected function path(): string
    {
        return '/health/ready';
    }
}
