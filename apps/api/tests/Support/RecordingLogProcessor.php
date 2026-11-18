<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Tagged as a processor of every logger in the test environment (config/services.yaml): keeps every record of every
 * channel, so a test can check what was logged (the handlers of the environment drop it).
 */
final class RecordingLogProcessor implements ProcessorInterface
{
    /** @var list<string> */
    private array $records = [];

    public function __invoke(LogRecord $record): LogRecord
    {
        $this->records[] = $record->channel.': '.$record->message.' '.json_encode($record->context, \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);

        return $record;
    }

    public function clear(): void
    {
        $this->records = [];
    }

    /**
     * @return list<string>
     */
    public function records(): array
    {
        return $this->records;
    }
}
