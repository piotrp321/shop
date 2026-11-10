<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Sending: a message without the stamp gets one with the current identifier (a new one when there is none), so the
 * transport stores it with the message. Receiving (ReceivedStamp, the worker): the message is handled under the
 * identifier of its stamp (a new one when it has none) and the previous identifier is restored afterwards, also when
 * the handler throws. The bus runs it before the validation and the transaction, so their log entries have it too.
 * In the worker CorrelationIdWorkerSubscriber has set the identifier already (and keeps it until the worker is done
 * with the message), so what is restored here is that identifier: the entries of the retry and failure listeners,
 * which come after the bus, still have it.
 */
final readonly class CorrelationIdMiddleware implements MiddlewareInterface
{
    public function __construct(private CorrelationId $correlationId)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $stamp = $envelope->last(CorrelationIdStamp::class);

        if (null === $envelope->last(ReceivedStamp::class)) {
            if (null === $stamp) {
                $envelope = $envelope->with(new CorrelationIdStamp($this->correlationId->current() ?? Uuid::v7()->toRfc4122()));
            }

            return $stack->next()->handle($envelope, $stack);
        }

        $previous = $this->correlationId->current();
        if (null === $stamp) {
            $this->correlationId->start();
        } else {
            $this->correlationId->set($stamp->id);
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->correlationId->set($previous);
        }
    }
}
