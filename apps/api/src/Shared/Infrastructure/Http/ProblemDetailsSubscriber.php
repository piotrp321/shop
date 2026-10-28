<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Exception\ProblemException;
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
 * Only the thrown exception itself decides about `detail`, its `previous` chain is never inspected for it:
 * - {@see ProblemException}: its own status and message (the message is written for clients),
 * - framework HTTP exceptions: status and headers are kept, `detail` is a fixed text per status, never the message
 *   (router messages contain the requested URL),
 * - anything else: a 500 without `detail`, so messages, classes and paths never leak, also in debug mode.
 */
final class ProblemDetailsSubscriber implements EventSubscriberInterface
{
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
        $exception = $event->getThrowable();
        $problem = ['type' => 'about:blank'];
        $headers = [];

        $validation = $this->findValidationFailure($exception);
        if (null !== $validation) {
            $status = Response::HTTP_BAD_REQUEST;
            $problem['title'] = Response::$statusTexts[$status];
            $problem['status'] = $status;
            $problem['violations'] = [];
            foreach ($validation->getViolations() as $violation) {
                $problem['violations'][] = [
                    'propertyPath' => $violation->getPropertyPath(),
                    'title' => (string) $violation->getMessage(),
                ];
            }
        } elseif ($exception instanceof ProblemException) {
            $status = $exception->problemStatus();
            $problem['title'] = Response::$statusTexts[$status] ?? 'Error';
            $problem['status'] = $status;
            if ($status < 500 && '' !== $exception->getMessage()) {
                $problem['detail'] = $exception->getMessage();
            }
        } elseif ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $headers = $exception->getHeaders();
            $problem['title'] = Response::$statusTexts[$status] ?? 'Error';
            $problem['status'] = $status;
            if ($status < 500) {
                $problem['detail'] = self::GENERIC_DETAILS[$status] ?? self::FALLBACK_DETAIL;
            }
        } else {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $problem['title'] = Response::$statusTexts[$status];
            $problem['status'] = $status;
        }

        $response = new JsonResponse($problem, $status, $headers);
        $response->headers->set('Content-Type', 'application/problem+json');

        $event->setResponse($response);
    }

    private function findValidationFailure(\Throwable $exception): ?ValidationFailedException
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof ValidationFailedException) {
                return $current;
            }
        }

        return null;
    }
}
