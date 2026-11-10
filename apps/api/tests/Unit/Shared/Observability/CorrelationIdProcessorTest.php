<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Observability;

use App\Shared\Infrastructure\Observability\CorrelationId;
use App\Shared\Infrastructure\Observability\CorrelationIdProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class CorrelationIdProcessorTest extends TestCase
{
    public function testTheEntryGetsTheCurrentIdentifierInExtra(): void
    {
        $correlationId = new CorrelationId();
        $correlationId->set('0198a1f0-0000-7000-8000-0000000000a1');

        $record = (new CorrelationIdProcessor($correlationId))($this->record(['request_id' => 'kept']));

        self::assertSame('0198a1f0-0000-7000-8000-0000000000a1', $record->extra['correlation_id']);
        self::assertSame('kept', $record->extra['request_id']);
    }

    public function testWithoutAnIdentifierTheEntryIsUntouched(): void
    {
        $record = (new CorrelationIdProcessor(new CorrelationId()))($this->record([]));

        self::assertArrayNotHasKey('correlation_id', $record->extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function record(array $extra): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'message', [], $extra);
    }
}
