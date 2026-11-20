<?php

declare(strict_types=1);

namespace App\Delivery\Application\Demo;

/**
 * A demo delivery method as the file gives it, already checked by DemoDeliveryMethodList; the seed creates it active.
 */
final readonly class DemoDeliveryMethod
{
    public function __construct(
        public string $name,
        public ?string $description,
        public int $priceGross,
        public ?int $freeFromGross,
        public int $position,
        public ?string $trackingUrlTemplate,
    ) {
    }
}
