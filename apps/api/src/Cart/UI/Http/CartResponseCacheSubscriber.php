<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every response under the cart paths is `private, no-store`: successes, problems and the 401 of the firewall alike,
 * and also a 404 of a path that matches no route (an id that is not a UUID).
 */
final class CartResponseCacheSubscriber implements EventSubscriberInterface
{
    private const string PATHS = '#^/api/v1/(guest-carts|me/cart)(/|$)#';

    public static function getSubscribedEvents(): array
    {
        // Late, after anything else that may have set caching headers.
        return [KernelEvents::RESPONSE => ['onResponse', -200]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || 1 !== preg_match(self::PATHS, $event->getRequest()->getPathInfo())) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->remove('Cache-Control');
        $headers->set('Cache-Control', 'private, no-store');
    }
}
