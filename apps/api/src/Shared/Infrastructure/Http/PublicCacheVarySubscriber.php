<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every publicly cacheable response must vary on Origin, because CORS headers depend on it.
 */
final class PublicCacheVarySubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        if (!$response->headers->hasCacheControlDirective('public')) {
            return;
        }

        $vary = [];
        foreach ($response->headers->all('Vary') as $line) {
            foreach (explode(',', (string) $line) as $name) {
                $name = trim($name);
                if ('' !== $name) {
                    $vary[] = $name;
                }
            }
        }

        foreach ($vary as $name) {
            if ('*' === $name || 0 === strcasecmp($name, 'Origin')) {
                return;
            }
        }

        $vary[] = 'Origin';
        $response->headers->set('Vary', implode(', ', $vary));
    }
}
