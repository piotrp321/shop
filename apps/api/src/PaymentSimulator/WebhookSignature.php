<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

/**
 * The signatures of the two providers (spec: "Port PaymentProvider i adaptery"); the adapters of Payment verify them.
 */
final class WebhookSignature
{
    /** `X-Signature` of demo-transfer: hex HMAC-SHA256 of the raw body. */
    public static function transfer(#[\SensitiveParameter] string $secret, string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }

    /** `Card-Signature` of demo-card: `t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`. */
    public static function card(#[\SensitiveParameter] string $secret, int $timestamp, string $rawBody): string
    {
        return \sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret));
    }
}
