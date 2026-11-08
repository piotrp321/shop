<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Records every dispatched message instead of handling it.
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];
    /** @var list<Envelope> the dispatched messages with the stamps given */
    public array $envelopes = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;
        $envelope = Envelope::wrap($message, $stamps);
        $this->envelopes[] = $envelope;

        return $envelope;
    }

    /**
     * @param class-string<T> $class
     *
     * @return list<T>
     *
     * @template T of object
     */
    public function dispatchedOf(string $class): array
    {
        return array_values(array_filter($this->dispatched, static fn (object $m): bool => $m instanceof $class));
    }
}
