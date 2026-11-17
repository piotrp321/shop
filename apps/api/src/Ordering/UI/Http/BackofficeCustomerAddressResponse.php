<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http;

use App\Ordering\Application\View\ShippingAddressView;
use OpenApi\Attributes as OA;

#[OA\Schema(description: 'A shipping address of the orders of the customer.', required: ['street', 'postalCode', 'city', 'country'])]
final readonly class BackofficeCustomerAddressResponse
{
    public function __construct(
        #[OA\Property(example: 'Długa 1')]
        public string $street,
        #[OA\Property(example: '00-950')]
        public string $postalCode,
        #[OA\Property(example: 'Warszawa')]
        public string $city,
        #[OA\Property(description: 'ISO 3166-1 alpha-2.', example: 'PL')]
        public string $country,
    ) {
    }

    public static function of(ShippingAddressView $address): self
    {
        return new self($address->street, $address->postalCode, $address->city, $address->country);
    }
}
