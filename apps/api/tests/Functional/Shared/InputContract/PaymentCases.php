<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * Rows of the input contract of the operations of Payment.
 */
final class PaymentCases
{
    private const string BODY = '{"provider":"demo-transfer"}';

    /**
     * @return iterable<InputCase>
     */
    public static function all(): iterable
    {
        // The guest reaches the order with its access token; without it the start is the 404 of an unknown order.
        yield from IdempotencyKeyRows::of('POST /api/v1/orders/{orderId}/payments', null, 'guestOrder', self::BODY, ['Order-Access-Token' => '{accessToken}']);
        yield from IdempotencyKeyRows::of('POST /api/v1/me/orders/{orderId}/payments', InputCase::CUSTOMER, 'customerOrder', self::BODY);
    }
}
