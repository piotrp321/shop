<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

final readonly class ShippingAddressView
{
    public function __construct(
        public string $street,
        public string $postalCode,
        public string $city,
        public string $country,
    ) {
    }
}
