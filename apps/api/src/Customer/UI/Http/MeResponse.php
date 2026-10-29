<?php

declare(strict_types=1);

namespace App\Customer\UI\Http;

use App\Customer\Application\Security\AuthenticatedCustomer;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The customer identified by the access token.')]
final readonly class MeResponse
{
    public function __construct(
        #[OA\Property(description: 'Customer id, the `sub` of the token.', example: '58276b79-2248-4817-8b28-5dbbb4f343ab')]
        public string $id,
        #[OA\Property(example: 'jan.kowalski@example.com')]
        public string $email,
        #[OA\Property(description: 'Full name, empty when the account has none.', example: 'Jan Kowalski')]
        public string $name,
    ) {
    }

    public static function of(AuthenticatedCustomer $customer): self
    {
        return new self($customer->id(), $customer->email(), $customer->name());
    }
}
