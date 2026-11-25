<?php

declare(strict_types=1);

namespace App\Delivery\Application\Command;

use App\Delivery\Domain\DeliveryMethodDescription;
use App\Delivery\Domain\DeliveryMethodName;
use App\Delivery\Domain\DeliveryPosition;
use App\Delivery\Domain\DeliveryPrice;
use App\Delivery\Domain\FreeDeliveryThreshold;
use App\Delivery\Domain\InvalidDeliveryMethod;
use App\Delivery\Domain\TrackingUrlTemplate;

/**
 * The values of a method a command gives, shared by the create and the revise: built in the order of the fields of a
 * request, so the first invalid one is the one reported.
 */
final readonly class DeliveryMethodTerms
{
    private function __construct(
        public DeliveryMethodName $name,
        public DeliveryMethodDescription $description,
        public DeliveryPrice $price,
        public ?FreeDeliveryThreshold $freeFrom,
        public DeliveryPosition $position,
        public ?TrackingUrlTemplate $trackingUrlTemplate,
    ) {
    }

    /**
     * @throws InvalidDeliveryMethod
     */
    public static function of(string $name, ?string $description, int $priceGross, ?int $freeFromGross, int $position, ?string $trackingUrlTemplate): self
    {
        return new self(
            DeliveryMethodName::of($name),
            DeliveryMethodDescription::of($description),
            DeliveryPrice::of($priceGross),
            FreeDeliveryThreshold::optional($freeFromGross),
            DeliveryPosition::of($position),
            TrackingUrlTemplate::optional($trackingUrlTemplate),
        );
    }
}
