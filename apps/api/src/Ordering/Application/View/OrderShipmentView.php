<?php

declare(strict_types=1);

namespace App\Ordering\Application\View;

/**
 * The shipment of an order that has a tracking number: the carrier (the name of the delivery method, null for an order
 * without one), the number and the link that follows it (the copy of the template of the method with the number, null
 * without a template). An order without a number has no shipment.
 */
final readonly class OrderShipmentView
{
    public function __construct(
        public ?string $carrier,
        public string $trackingNumber,
        public ?string $trackingUrl,
    ) {
    }
}
