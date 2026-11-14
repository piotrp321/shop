<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use App\Shared\Infrastructure\Http\ExceptionStatus;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionMechanism;
use Sentry\State\HubInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The one channel of the HTTP errors to Sentry (spec: docs/features/sentry/spec.md, D5; the listener of the bundle is
 * off): an exception is reported when the response it ends in is a server error (5xx). The status is the one that
 * ProblemDetailsSubscriber renders (ExceptionStatus), so there is no second list of exceptions; a 4xx, also of a project
 * exception (NotFound, Conflict, InvalidInput), is never an event.
 *
 * It listens just before ProblemDetailsSubscriber, which sets the response and so stops the propagation of the event,
 * and after the framework logged the exception (priority 0). An exception that a firewall turns into a response first
 * (401, 403) does not reach it.
 */
final readonly class ServerErrorReporter implements EventSubscriberInterface
{
    public function __construct(private HubInterface $hub)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -99]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (ExceptionStatus::of($exception) < 500) {
            return;
        }

        $this->hub->captureEvent(Event::createEvent(), EventHint::fromArray([
            'exception' => $exception,
            'mechanism' => new ExceptionMechanism(ExceptionMechanism::TYPE_GENERIC, false),
        ]));
    }
}
