<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * The route defaults that put a route under a rate limit (spec: docs/features/hardening/spec.md, "Rate limiting"):
 * `#[Route(..., defaults: [RateLimit::ROUTE_DEFAULT => RateLimit::ORDER_PLACEMENT, RateLimit::KEY_ROUTE_DEFAULT => RateLimit::BY_GUEST])]`.
 * RateLimitSubscriber reads them; the limiters and their values are in config/packages/rate_limiter.yaml.
 */
final class RateLimit
{
    /** The name of the limiter of the route, one of the limiter constants below. */
    public const string ROUTE_DEFAULT = '_rate_limit';

    /** What the limit of the route counts by, one of the key constants below (by the guest address when absent). */
    public const string KEY_ROUTE_DEFAULT = '_rate_limit_key';

    /**
     * Optional: the name of a query parameter. The limit then counts only a request whose query has the parameter with
     * a value other than the empty string, as given (spaces, white space of Unicode or an array count too: the limit
     * trims nothing, so it never takes for empty what the controller takes for a value).
     */
    public const string QUERY_ROUTE_DEFAULT = '_rate_limit_query';

    public const string ORDER_PLACEMENT = 'order_placement';
    public const string PAYMENT_START = 'payment_start';
    public const string PAYMENT_WEBHOOK = 'payment_webhook';
    public const string GUEST_CART_CREATE = 'guest_cart_create';
    public const string GUEST_CART_WRITE = 'guest_cart_write';
    public const string DISCOUNT_PREVIEW = 'discount_preview';

    /** `guest:<address>` (ClientIp). */
    public const string BY_GUEST = 'guest';

    /** `customer:<sub>`: the account of the token, whatever the address. */
    public const string BY_CUSTOMER = 'customer';

    /** `<provider>:<address>`: the `provider` of the route and the address that sends its webhooks. */
    public const string BY_PROVIDER = 'provider';
}
