<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the correlation identifier with a message: through the Doctrine transports (PHP serializer) and the AMQP
 * headers of `events` (Symfony serializer, which builds it again through the constructor).
 */
final readonly class CorrelationIdStamp implements StampInterface
{
    public function __construct(public string $id)
    {
    }
}
