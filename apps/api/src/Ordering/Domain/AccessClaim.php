<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Who asks for an order: a guest by the access token of the order, or a customer by the `sub` of the token.
 * Order::grantsAccess() decides.
 */
final readonly class AccessClaim
{
    private function __construct(
        private ?string $token,
        private ?string $customerId,
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

    public function token(): ?string
    {
        return $this->token;
    }

    public function customerId(): ?string
    {
        return $this->customerId;
    }
}
