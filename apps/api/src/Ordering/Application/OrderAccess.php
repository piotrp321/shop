<?php

declare(strict_types=1);

namespace App\Ordering\Application;

use App\Ordering\Domain\AccessClaim;

/**
 * How the caller reaches an order: a guest by the access token given at the placement, a customer by the `sub` of
 * the customer's token. Order::grantsAccess() decides.
 */
final readonly class OrderAccess
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

    public function claim(): AccessClaim
    {
        return null !== $this->token ? AccessClaim::guest($this->token) : AccessClaim::customer($this->customerId ?? '');
    }
}
