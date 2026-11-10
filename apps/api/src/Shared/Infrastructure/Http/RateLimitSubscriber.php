<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit as Limit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Applies the rate limit that a route names in its RateLimit::ROUTE_DEFAULT (spec: docs/features/hardening/spec.md,
 * D4–D8): after the firewall, before the controller, so a refused request reads no body, checks no signature and
 * dispatches no command. Every request of the route uses the limit, also a repeated Idempotency-Key and one that the
 * validation refuses. Over the limit: a 429 problem with a Polish `detail`, `Retry-After` in seconds and
 * `private, no-store`.
 *
 * The key (RateLimit::KEY_ROUTE_DEFAULT): `guest:<address>`, `customer:<sub>` or `<provider>:<address>`, with the
 * address of ClientIp; the prefixes keep the spaces of guests and customers apart. A customer route without a user
 * (the firewall refuses it first) counts by the guest address.
 */
final readonly class RateLimitSubscriber implements EventSubscriberInterface
{
    public const string DETAIL = 'Zbyt wiele prób. Spróbuj ponownie za kilka minut.';

    public function __construct(
        #[AutowireLocator('rate_limiter', indexAttribute: 'name')]
        private ContainerInterface $limiters,
        private ClientIp $clientIp,
        private TokenStorageInterface $tokens,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Right after the firewall (8), which authenticates the customer of the token.
        return [KernelEvents::REQUEST => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $name = $request->attributes->get(RateLimit::ROUTE_DEFAULT);
        if (!$event->isMainRequest() || !\is_string($name)) {
            return;
        }

        $factory = $this->limiters->get($name);
        \assert($factory instanceof RateLimiterFactoryInterface);
        $limit = $factory->create($this->key($request))->consume();
        if (!$limit->isAccepted()) {
            $event->setResponse(self::tooManyRequests($limit));
        }
    }

    private function key(Request $request): string
    {
        $kind = $request->attributes->get(RateLimit::KEY_ROUTE_DEFAULT);
        $customer = (string) $this->tokens->getToken()?->getUserIdentifier();
        if (RateLimit::BY_CUSTOMER === $kind && '' !== $customer) {
            return 'customer:'.$customer;
        }
        if (RateLimit::BY_PROVIDER === $kind) {
            return $request->attributes->getString('provider').':'.$this->clientIp->of($request);
        }

        return 'guest:'.$this->clientIp->of($request);
    }

    private static function tooManyRequests(Limit $limit): JsonResponse
    {
        $seconds = max(1, (int) ceil((float) $limit->getRetryAfter()->format('U.u') - microtime(true)));
        $status = Response::HTTP_TOO_MANY_REQUESTS;
        $response = new JsonResponse(
            ['type' => 'about:blank', 'title' => Response::$statusTexts[$status], 'status' => $status, 'detail' => self::DETAIL],
            $status,
            ['Retry-After' => (string) $seconds, 'Cache-Control' => 'private, no-store'],
        );
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }
}
