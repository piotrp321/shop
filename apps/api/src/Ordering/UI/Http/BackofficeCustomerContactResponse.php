<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use OpenApi\Attributes as OA;

#[OA\Schema(description: 'The contact of the newest order of the customer.', required: ['email', 'fullName', 'phone'])]
final readonly class BackofficeCustomerContactResponse
{
    public function __construct(
        #[OA\Property(example: 'anna.nowak@example.com')]
        public string $email,
        #[OA\Property(example: 'Anna Nowak')]
        public string $fullName,
        #[OA\Property(description: '+48 and 9 digits.', example: '+48600100200')]
        public string $phone,
    ) {
    }
}
