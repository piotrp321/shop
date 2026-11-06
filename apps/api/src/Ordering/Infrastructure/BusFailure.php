<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure;

use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * The bus wraps what a handler of another module threw; the adapters pass on the cause (e.g. CartNotFound of Cart,
 * a lost connection), never swallow it.
 */
final class BusFailure
{
    public static function cause(HandlerFailedException $failure): \Throwable
    {
        $wrapped = $failure->getWrappedExceptions();

        return 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;
    }
}
