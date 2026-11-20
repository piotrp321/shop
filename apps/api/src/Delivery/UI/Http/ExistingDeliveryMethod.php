<?php

declare(strict_types=1);

namespace App\Delivery\UI\Http;

/**
 * Marks a controller whose route names a delivery method in `{id}`: an unknown method (also an id that is no UUID) is
 * a 404 after the checks of #[IsGranted] and before the body is read (W1), see DeliveryMethodExistenceListener.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ExistingDeliveryMethod
{
}
