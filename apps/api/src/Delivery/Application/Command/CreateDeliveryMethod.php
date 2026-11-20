<?php

declare(strict_types=1);

namespace App\Delivery\Application\Command;

/**
 * Creates a delivery method (the panel and the demo data).
 */
final readonly class CreateDeliveryMethod
{
    /**
     * @param string      $id                  the UUID of the new method, chosen by the caller
     * @param string|null $description         null and white space only are no description
     * @param int         $priceGross          grosze
     * @param int|null    $freeFromGross       grosze; null for no threshold
     * @param string|null $trackingUrlTemplate null and white space only are no template
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public int $priceGross,
        public ?int $freeFromGross,
        public bool $active,
        public int $position,
        public ?string $trackingUrlTemplate,
    ) {
    }
}
