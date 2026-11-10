<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every response of the API carries `X-Content-Type-Options: nosniff` (spec: docs/features/hardening/spec.md, D10):
 * the responses are data, never to be interpreted as another type. `kernel.response` follows every response a request
 * ends with, so errors, 404, 429 and the preflight answered by the CORS bundle get the header too.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $event->getResponse()->headers->set('X-Content-Type-Options', 'nosniff');
    }
}
