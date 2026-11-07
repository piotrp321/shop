<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * The shared test vector of the signatures of provider webhooks (spec: "Port PaymentProvider i adaptery"): the
 * simulator signs these bodies with these secrets, and the adapters of Payment must accept exactly these signatures.
 * The hex values were computed independently of the code (Python hmac), so a test against them does not repeat the
 * implementation.
 *
 * - demo-transfer: `X-Signature` is the hex HMAC-SHA256 of the raw body;
 * - demo-card: `Card-Signature: t=<unix>,v1=<hex>`, the HMAC-SHA256 of `<t>.<raw body>`.
 */
final class WebhookSignatureVector
{
    public const string TRANSACTION_ID = '0f8b6c1e-2d3a-4b5c-8d9e-0a1b2c3d4e5f';
    public const string EVENT_ID = 'evt_0f8b6c1e2d3a4b5c8d9e0a1b2c3d4e5f';
    public const string REFERENCE = 'R-100001';
    /** 123.45 PLN. */
    public const int AMOUNT = 12_345;
    /** 2026-11-06 12:00:00 UTC: the moment of the payment in both bodies. */
    public const int EXECUTED_AT = 1_793_966_400;

    public const string TRANSFER_SECRET = 'transfer-webhook-secret-for-tests';
    public const string TRANSFER_BODY = '{"eventId":"evt_0f8b6c1e2d3a4b5c8d9e0a1b2c3d4e5f","transactionId":"0f8b6c1e-2d3a-4b5c-8d9e-0a1b2c3d4e5f","merchantRef":"R-100001","status":"PAID","amount":12345,"currency":"PLN","paidAt":"2026-11-06T12:00:00Z"}';
    public const string TRANSFER_SIGNATURE = 'b44dbf83c1ad8b41c598602e5d8cac908ab5007f6cb849a62b683da6d08a247c';

    public const string CARD_SECRET = 'card-webhook-secret-for-tests';
    public const string CARD_BODY = '{"id":"evt_0f8b6c1e2d3a4b5c8d9e0a1b2c3d4e5f","type":"checkout.completed","data":{"sessionId":"0f8b6c1e-2d3a-4b5c-8d9e-0a1b2c3d4e5f","reference":"R-100001","amount":"123.45","currency":"PLN","completedAt":1793966400}}';
    /** The moment of signing, one second after the payment. */
    public const int CARD_TIMESTAMP = 1_793_966_401;
    public const string CARD_SIGNATURE = '11884adcda5e67b1e4617d4a988c604e3763f8ea4ba399bfa6d178cdc399dc29';
    public const string CARD_HEADER = 't=1793966401,v1=11884adcda5e67b1e4617d4a988c604e3763f8ea4ba399bfa6d178cdc399dc29';
}
