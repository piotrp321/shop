<?php

declare(strict_types=1);

namespace App\Backoffice\Infrastructure;

use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * What the adapters of the dashboard share: a question to another module through the query bus, the one dependency
 * between the modules, answered without the wrapper of the bus around the failure of the handler.
 */
trait AsksQueryBus
{
    private static function ask(MessageBusInterface $queryBus, object $query): mixed
    {
        try {
            $envelope = $queryBus->dispatch($query);
        } catch (HandlerFailedException $failure) {
            // The bus wraps what the handler threw; the caller deals with the cause (e.g. a lost connection).
            $wrapped = $failure->getWrappedExceptions();

            throw 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;
        }
        $handled = $envelope->last(HandledStamp::class);
        if (!$handled instanceof HandledStamp) {
            throw new \LogicException(\sprintf('No handler answered %s on the query bus.', $query::class));
        }

        return $handled->getResult();
    }
}
