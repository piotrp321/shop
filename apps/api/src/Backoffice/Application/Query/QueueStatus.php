<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

final readonly class QueueStatus
{
    public function __construct(
        public string $name,
        public int $messages,
        public int $consumers,
    ) {
    }
}
