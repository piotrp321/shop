<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Adds the current correlation identifier to every log entry as extra.correlation_id.
 */
#[AsMonologProcessor]
final readonly class CorrelationIdProcessor
{
    public function __construct(private CorrelationId $correlationId)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $id = $this->correlationId->current();
        if (null === $id) {
            return $record;
        }

        $record->extra['correlation_id'] = $id;

        return $record;
    }
}
