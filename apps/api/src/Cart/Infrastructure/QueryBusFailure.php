<?php

declare(strict_types=1);

namespace App\Cart\Infrastructure;

use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * The bus wraps what a handler of another module threw; the adapters pass on the cause (e.g. a lost connection).
 */
final class QueryBusFailure
{
    public static function cause(HandlerFailedException $failure): \Throwable
    {
        $wrapped = $failure->getWrappedExceptions();

        return 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;
    }
}
