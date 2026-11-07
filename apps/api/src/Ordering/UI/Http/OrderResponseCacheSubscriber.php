<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every response under the order paths (of the store and of the panel) is `private, no-store`: orders, problems, and
 * the 401, 403 and 503 of the firewall alike.
 */
final class OrderResponseCacheSubscriber implements EventSubscriberInterface
{
    private const string PATHS = '#^/api/v1/(orders|me/orders|admin/orders)(/|$)#';

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
