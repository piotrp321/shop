<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

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
 * Unknown exceptions become a 500 without any detail, so messages, classes and paths never leak, also in debug mode.
 */
final class ProblemDetailsSubscriber implements EventSubscriberInterface
{
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
        } elseif ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $headers = $exception->getHeaders();
            $problem['title'] = Response::$statusTexts[$status] ?? 'Error';
            $problem['status'] = $status;
            if ($status < 500 && '' !== $exception->getMessage()) {
                $problem['detail'] = $exception->getMessage();
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
