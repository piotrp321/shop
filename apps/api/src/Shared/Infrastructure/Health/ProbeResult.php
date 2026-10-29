<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

final readonly class ProbeResult
{
    /**
     * @param ?int $latencyMs how long the service took to answer, null when it is down
     */
    public function __construct(
        public bool $up,
        public ?int $latencyMs,
    ) {
    }

    public static function upIn(int $latencyMs): self
    {
        return new self(true, max(0, $latencyMs));
    }

    public static function down(): self
    {
        return new self(false, null);
    }
}
