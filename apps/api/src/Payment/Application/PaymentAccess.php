<?php

declare(strict_types=1);

namespace App\Payment\Application;

/**
 * How the caller reaches an order: a guest by the access token of the order (header `Order-Access-Token`), a
 * customer by the `sub` of the token of the session. Ordering decides (GetOrderForPayment); the token never leaves
 * Ordering's rule.
 */
final readonly class PaymentAccess
{
    private function __construct(
        public ?string $token,
        public ?string $customerId,
    ) {
    }

    public static function guest(string $token): self
    {
        return new self($token, null);
    }

    public static function customer(string $customerId): self
    {
        return new self(null, $customerId);
    }
}
