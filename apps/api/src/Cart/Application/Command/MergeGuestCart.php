<?php

declare(strict_types=1);

namespace App\Cart\Application\Command;

/**
 * Merges the guest cart into the cart of the customer who logged in (`sub` of the token).
 */
final readonly class MergeGuestCart
{
    public function __construct(
        public string $customerId,
        public string $guestCartId,
    ) {
    }
}
