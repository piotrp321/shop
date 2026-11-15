<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Category;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every response under the category paths of the panel is `private, no-store`: the tree, the saved categories, the
 * problems, and the 401, 403 and 503 of the firewall alike.
 */
final class CategoryResponseCacheSubscriber implements EventSubscriberInterface
{
    private const string PATHS = '#^/api/v1/admin/categories(/|$)#';

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
