<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives every HTTP request of the API its own identifier (a UUIDv7; a header of the request is never trusted) and
 * returns it in X-Correlation-ID on every response, also a 4xx, a 429 and an error. A console command gets one for
 * the whole run, except `messenger:consume`, where each message has its own (CorrelationIdMiddleware).
 */
final readonly class CorrelationIdSubscriber implements EventSubscriberInterface
{
    public const string HEADER = 'X-Correlation-ID';

    public function __construct(private CorrelationId $correlationId)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Before the firewall (8) and the rate limiter (4): whatever refuses or logs the request has the identifier.
            KernelEvents::REQUEST => ['onRequest', 256],
            KernelEvents::RESPONSE => 'onResponse',
            ConsoleEvents::COMMAND => 'onCommand',
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->correlationId->start();
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->set(self::HEADER, $this->correlationId->current() ?? $this->correlationId->start());
        }
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        if ('messenger:consume' !== $event->getCommand()?->getName()) {
            $this->correlationId->start();
        }
    }
}
