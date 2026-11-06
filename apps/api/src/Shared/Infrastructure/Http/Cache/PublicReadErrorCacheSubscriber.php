<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Cache;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The 400 (bad parameters), 401 (the firewall: an invalid token) and 503 (an outage) of a public read cached by
 * Varnish (a route with the ROUTE_DEFAULT default) are `private, no-store`, without cache tags. Other statuses are
 * left as they are.
 */
final class PublicReadErrorCacheSubscriber implements EventSubscriberInterface
{
    /** Route default of the public reads: `#[Route(..., defaults: [self::ROUTE_DEFAULT => true])]`. */
    public const string ROUTE_DEFAULT = '_public_read';

    private const array STATUSES = [400, 401, 503];

    public static function getSubscribedEvents(): array
    {
        // Late, after anything else that may have set caching headers.
        return [KernelEvents::RESPONSE => ['onResponse', -200]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || true !== $event->getRequest()->attributes->get(self::ROUTE_DEFAULT)) {
            return;
        }

        $response = $event->getResponse();
        if (!\in_array($response->getStatusCode(), self::STATUSES, true)) {
            return;
        }

        $response->headers->remove(CacheTagger::HEADER);
        $response->headers->remove('Cache-Control');
        $response->headers->set('Cache-Control', 'private, no-store');
    }
}
