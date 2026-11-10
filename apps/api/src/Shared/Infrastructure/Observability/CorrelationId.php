<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The correlation identifier of the work this process is doing now: a request, a console command or a message of the
 * worker (spec: docs/features/hardening/spec.md, D9, "Correlation ID"). Set by CorrelationIdSubscriber and
 * CorrelationIdMiddleware, written into the log entries by CorrelationIdProcessor.
 *
 * The kernel resets it (kernel.reset) after each request and each message, so a long-running process (a FrankenPHP
 * worker, `messenger:consume`) never carries an identifier over to the next piece of work.
 */
final class CorrelationId implements ResetInterface
{
    private ?string $current = null;

    public function current(): ?string
    {
        return $this->current;
    }

    /**
     * Makes up a new UUIDv7 and makes it the current identifier.
     */
    public function start(): string
    {
        return $this->current = Uuid::v7()->toRfc4122();
    }

    public function set(?string $id): void
    {
        $this->current = $id;
    }

    public function reset(): void
    {
        $this->current = null;
    }
}
