<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use Psr\Log\AbstractLogger;

final class SpyLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> level, message and context of every record */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        \assert(\is_string($level));
        $this->records[] = [$level, (string) $message, $context];
    }
}
