<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health\Probe;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The management API of RabbitMQ: it answers only while the broker runs, and it is what the dashboard reads queues from.
 */
final class RabbitMqProbe extends HttpProbe
{
    public function __construct(
        HttpClientInterface $client,
        string $baseUrl,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
    ) {
        parent::__construct($client, $baseUrl);
    }

    public function name(): string
    {
        return 'rabbitmq';
    }

    protected function path(): string
    {
        return '/api/health/checks/alarms';
    }

    protected function options(): array
    {
        return ['auth_basic' => [$this->user, $this->password]];
    }
}
