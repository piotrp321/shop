<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\InvalidInput;
use App\Shared\Domain\Exception\NotFound;
use App\Shared\Domain\Exception\ProblemException;
use App\Shared\Domain\Exception\Unavailable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * The HTTP status of an exception: the one mapping of the API, used by ProblemDetailsSubscriber for the problem
 * document and by the Sentry reporting of server errors (spec: docs/features/sentry/spec.md, D5). First match wins:
 * - a validation failure (also as the `previous` of an HTTP exception): 400, or 422 when the HTTP exception that wraps
 *   it is a 422 (a request payload),
 * - {@see ProblemException}: status by kind (see PROBLEM_STATUSES), a ProblemException of no known kind is invalid
 *   input; it wins over an HttpExceptionInterface of the same class,
 * - AuthenticationException 401, AccessDeniedException 403 (thrown outside a firewall),
 * - framework HTTP exceptions: their status,
 * - anything else: 500.
 *
 * A Messenger HandlerFailedException that wraps exactly one exception stands for that exception, repeatedly for nested
 * buses, because a sync bus wraps whatever a handler throws.
 */
final class ExceptionStatus
{
    /**
     * Kind of project exception to HTTP status, first match wins.
     */
    private const array PROBLEM_STATUSES = [
        NotFound::class => Response::HTTP_NOT_FOUND,
        Conflict::class => Response::HTTP_CONFLICT,
        InvalidInput::class => Response::HTTP_UNPROCESSABLE_ENTITY,
        Unavailable::class => Response::HTTP_SERVICE_UNAVAILABLE,
    ];

    private const int MAX_UNWRAP_DEPTH = 10;

    private function __construct()
    {
    }

    public static function of(\Throwable $exception): int
    {
        $exception = self::unwrapHandlerFailure($exception);

        return match (true) {
            null !== self::validationFailure($exception) => self::validationStatus($exception),
            $exception instanceof ProblemException => self::problemStatus($exception),
            $exception instanceof AuthenticationException => Response::HTTP_UNAUTHORIZED,
            $exception instanceof AccessDeniedException => Response::HTTP_FORBIDDEN,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * Follows chains of HandlerFailedException that each wrap exactly one exception (nested buses). Anything else,
     * including more than one wrapped exception or a chain deeper than MAX_UNWRAP_DEPTH, is left as it is and ends up
     * as a 500.
     */
    public static function unwrapHandlerFailure(\Throwable $exception): \Throwable
    {
        for ($depth = 0; $depth < self::MAX_UNWRAP_DEPTH && $exception instanceof HandlerFailedException; ++$depth) {
            $wrapped = $exception->getWrappedExceptions();
            if (1 !== \count($wrapped)) {
                break;
            }
            $exception = reset($wrapped);
        }

        return $exception;
    }

    /**
     * The validation failure the exception is or carries in its `previous` chain; never one inside a
     * HandlerFailedException that was not unwrapped (it wraps several exceptions or is too deep).
     */
    public static function validationFailure(\Throwable $exception): ?ValidationFailedException
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof HandlerFailedException) {
                return null;
            }
            if ($current instanceof ValidationFailedException) {
                return $current;
            }
        }

        return null;
    }

    /**
     * A validation failure is a 400, unless an HTTP exception that wraps it is a 422: a request payload mapped with
     * `validationFailedStatusCode: 422`. Any other status of the wrapper is ignored (`#[MapQueryString]` wraps in a
     * 404 by default, but a bad query is a 400), so it is never a server error either.
     */
    private static function validationStatus(\Throwable $exception): int
    {
        if ($exception instanceof HttpExceptionInterface && Response::HTTP_UNPROCESSABLE_ENTITY === $exception->getStatusCode()) {
            return Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return Response::HTTP_BAD_REQUEST;
    }

    private static function problemStatus(ProblemException $exception): int
    {
        foreach (self::PROBLEM_STATUSES as $kind => $status) {
            if ($exception instanceof $kind) {
                return $status;
            }
        }

        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
