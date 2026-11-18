<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Search;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        \assert(\is_string($level));
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
