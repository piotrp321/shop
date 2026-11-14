<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Exception\ExtendedProblem;
use App\Shared\Domain\Exception\ProblemException;
use App\Shared\Domain\Exception\TypedProblem;
use App\Shared\Domain\Exception\Unavailable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Renders every exception as an RFC 9457 problem details document.
 *
 * A validation failure (also as the `previous` of an HTTP exception) lists its violations, without `detail`; its
 * status is 400, or 422 when the HTTP exception that wraps it is a 422 (a request payload).
 *
 * Only the thrown exception itself decides about `detail`, its `previous` chain is never inspected for it.
 * The one exception: a Messenger HandlerFailedException that wraps exactly one exception is replaced by that
 * exception, repeatedly for nested buses, because a sync bus wraps whatever a handler throws.
 * The status of every document is {@see ExceptionStatus::of()}, the one mapping of exceptions to statuses.
 * - {@see ProblemException}: status by kind, message as `detail` (written for clients; none for
 *   a 5xx such as {@see Unavailable}), `type`
 *   from {@see TypedProblem} when the exception has one, otherwise `about:blank`, and the extension members of an
 *   {@see ExtendedProblem} (never in place of a member of the problem, none for a 5xx).
 *   If a class is also an HttpExceptionInterface, the ProblemException rules win and its headers are ignored,
 * - framework HTTP exceptions: status and headers are kept, `detail` is a fixed text per status, never the message
 *   (router messages contain the requested URL),
 * - security exceptions that no firewall handled (thrown outside a firewall): AuthenticationException is a 401 with
 *   `WWW-Authenticate: Bearer`, AccessDeniedException a 403, both with the fixed text. The framework's ErrorListener
 *   usually turns them into HTTP exceptions first (their #[WithHttpStatus]); a handler's one arrives unwrapped from
 *   HandlerFailedException. Inside a firewall the security ExceptionListener handles them before this subscriber
 *   (and follows their `previous` chain, that is Symfony's rule): ProblemAuthenticationEntryPoint gives the 401,
 *   an AccessDeniedHttpException the 403,
 * - anything else: a 500 without `detail`, so messages, classes and paths never leak, also in debug mode.
 */
final class ProblemDetailsSubscriber implements EventSubscriberInterface
{
    /** The members of a problem document that an extension never replaces. */
    private const RESERVED_MEMBERS = ['type', 'title', 'status', 'detail', 'instance', 'violations'];

    private const FALLBACK_DETAIL = 'The request could not be processed.';

    /** Fixed texts for framework exceptions, they never depend on the exception message. */
    private const GENERIC_DETAILS = [
        Response::HTTP_BAD_REQUEST => 'The request is invalid.',
        Response::HTTP_UNAUTHORIZED => 'Authentication is required.',
        Response::HTTP_FORBIDDEN => 'Access is denied.',
        Response::HTTP_NOT_FOUND => 'Resource not found.',
        Response::HTTP_METHOD_NOT_ALLOWED => 'Method not allowed.',
        Response::HTTP_NOT_ACCEPTABLE => 'Requested representation is not available.',
        Response::HTTP_CONFLICT => 'The request conflicts with the current state.',
        Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'Unsupported media type.',
        Response::HTTP_UNPROCESSABLE_ENTITY => 'The request is invalid.',
        Response::HTTP_TOO_MANY_REQUESTS => 'Too many requests.',
    ];

    public static function getSubscribedEvents(): array
    {
        // After the framework logged the exception (priority 0), before it renders its own error page (-128).
        return [KernelEvents::EXCEPTION => ['onException', -100]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = ExceptionStatus::unwrapHandlerFailure($event->getThrowable());
        $status = ExceptionStatus::of($exception);

        $validation = ExceptionStatus::validationFailure($exception);
        $response = match (true) {
            null !== $validation => $this->validationProblem($validation, $status),
            $exception instanceof ProblemException => $this->projectProblem($exception, $status),
            $exception instanceof HttpExceptionInterface => self::genericProblem($status, $exception->getHeaders()),
            default => self::genericProblem($status),
        };

        $event->setResponse($response);
    }

    /**
     * A problem document whose `detail` is the fixed text of the status (none for 5xx), never an exception message.
     * A 401 always names the scheme the API accepts (`WWW-Authenticate: Bearer`) unless the exception gave one.
     *
     * @param array<mixed> $headers
     */
    public static function genericProblem(int $status, array $headers = []): JsonResponse
    {
        $problem = ['type' => 'about:blank', 'title' => Response::$statusTexts[$status] ?? 'Error', 'status' => $status];
        if ($status < 500) {
            $problem['detail'] = self::GENERIC_DETAILS[$status] ?? self::FALLBACK_DETAIL;
        }
        if (Response::HTTP_UNAUTHORIZED === $status && !\array_key_exists('www-authenticate', array_change_key_case($headers))) {
            $headers['WWW-Authenticate'] = 'Bearer';
        }

        return self::problemResponse($problem, $status, $headers);
    }

    private function validationProblem(ValidationFailedException $validation, int $status): JsonResponse
    {
        $problem = ['type' => 'about:blank', 'title' => Response::$statusTexts[$status] ?? 'Error', 'status' => $status, 'violations' => []];
        foreach ($validation->getViolations() as $violation) {
            $problem['violations'][] = [
                'propertyPath' => $violation->getPropertyPath(),
                'title' => (string) $violation->getMessage(),
            ];
        }

        return self::problemResponse($problem, $status);
    }

    private function projectProblem(ProblemException $exception, int $status): JsonResponse
    {
        $type = $exception instanceof TypedProblem ? $exception->problemType() : 'about:blank';
        $problem = ['type' => $type, 'title' => Response::$statusTexts[$status] ?? 'Error', 'status' => $status];
        if ($status < 500 && '' !== $exception->getMessage()) {
            $problem['detail'] = $exception->getMessage();
        }
        if ($status < 500 && $exception instanceof ExtendedProblem) {
            $problem += array_diff_key($exception->problemExtensions(), array_flip(self::RESERVED_MEMBERS));
        }

        return self::problemResponse($problem, $status);
    }

    /**
     * @param array<string, mixed> $problem
     * @param array<mixed>         $headers
     */
    private static function problemResponse(array $problem, int $status, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($problem, $status, $headers);
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}
