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

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;

        return Envelope::wrap($message, $stamps);
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
