<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * The address of the client as the rate limits count it (spec: docs/features/hardening/spec.md, D2 and D4), used by
 * nothing else.
 *
 * With CLIENT_IP_HEADER set (`CF-Connecting-IP` behind Cloudflare, where the ingress takes traffic from Cloudflare only
 * and Cloudflare always overwrites the header), the address is the value of that header when it is one valid IP
 * address, sent once. Otherwise (no variable, no header, several values, garbage) it is the address of the connection
 * (Request::getClientIp(), without trusted proxies). An IPv6 address counts by its /64 prefix, as one host usually
 * holds a whole /64; an IPv4-mapped IPv6 address counts as its IPv4 address. A request without any address (none of
 * the HTTP server) shares the key `unknown`.
 */
final readonly class ClientIp
{
    private const string UNKNOWN = 'unknown';

    /** Optional whitespace around a header value (RFC 9110), ASCII only: anything else makes the value invalid. */
    private const string OPTIONAL_WHITESPACE = " \t";

    private ?string $header;

    public function __construct(#[Autowire('%env(default::CLIENT_IP_HEADER)%')] ?string $header)
    {
        $this->header = null === $header || '' === $header ? null : $header;
    }

    public function of(Request $request): string
    {
        return self::key($this->fromHeader($request) ?? $request->getClientIp());
    }

    private function fromHeader(Request $request): ?string
    {
        if (null === $this->header) {
            return null;
        }
        $values = $request->headers->all($this->header);
        if (1 !== \count($values) || !\is_string($values[0])) {
            return null;
        }
        $address = trim($values[0], self::OPTIONAL_WHITESPACE);

        return false === filter_var($address, \FILTER_VALIDATE_IP) ? null : $address;
    }

    private static function key(?string $address): string
    {
        $binary = null === $address ? false : @inet_pton($address);
        if (false === $binary) {
            return self::UNKNOWN;
        }
        if (4 === \strlen($binary)) {
            return (string) inet_ntop($binary);
        }
        if (str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($binary, 12));
        }

        return inet_ntop(substr($binary, 0, 8).str_repeat("\0", 8)).'/64';
    }
}
