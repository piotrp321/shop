<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery;

use App\Delivery\Domain\DeliveryMethod;
use App\Delivery\Domain\DeliveryMethodDescription;
use App\Delivery\Domain\DeliveryMethodId;
use App\Delivery\Domain\DeliveryMethodName;
use App\Delivery\Domain\DeliveryPosition;
use App\Delivery\Domain\DeliveryPrice;
use App\Delivery\Domain\FreeDeliveryThreshold;
use App\Delivery\Domain\TrackingUrlTemplate;
use Symfony\Component\Uid\Uuid;

/**
 * Delivery methods for the unit tests: stored ones (version 1), built with the values given and defaults for the rest.
 */
final class DeliveryMethods
{
    public const string CREATED_AT = '2026-11-20T10:00:00+00:00';

    public static function stored(
        string $name = 'Kurier standardowy',
        int $price = 1999,
        ?int $freeFrom = null,
        bool $active = true,
        int $position = 10,
        ?string $id = null,
        string $description = '',
        ?string $template = null,
        int $version = 1,
    ): DeliveryMethod {
        $at = new \DateTimeImmutable(self::CREATED_AT);

        return DeliveryMethod::reconstitute(
            DeliveryMethodId::fromString($id ?? Uuid::v7()->toRfc4122()),
            DeliveryMethodName::of($name),
            DeliveryMethodDescription::of($description),
            DeliveryPrice::of($price),
            FreeDeliveryThreshold::optional($freeFrom),
            $active,
            DeliveryPosition::of($position),
            TrackingUrlTemplate::optional($template),
            $at,
            $at,
            $version,
        );
    }
}
