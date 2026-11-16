<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\Port\CategoryReindexBatches;

final class RecordingCategoryReindexBatches implements CategoryReindexBatches
{
    /** @var list<array{string, int}> [runId, batch] */
    public array $sent = [];

    public function send(string $runId, int $batch): void
    {
        $this->sent[] = [$runId, $batch];
    }
}
