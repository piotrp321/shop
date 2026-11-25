<?php

declare(strict_types=1);

namespace App\Delivery\Application\Command;

/**
 * Changes a delivery method, based on the version the administrator saw. The whole method is sent.
 */
final readonly class ReviseDeliveryMethod
{
    /**
     * @param string      $id                  any text; one that is no UUID is an unknown method
     * @param string|null $description         null and white space only are no description
     * @param int         $priceGross          grosze
     * @param int|null    $freeFromGross       grosze; null for no threshold
     * @param string|null $trackingUrlTemplate null and white space only are no template
     */
    public function __construct(
        public string $id,
        public int $expectedVersion,
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
