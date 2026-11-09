<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

/**
 * The value DiscountCodeBodyReader gives a key the JSON of a discount code body does not have, so its violation is
 * the Polish one of DiscountCodeBodyRules::keyGiven(). No JSON value is ever an instance of it.
 */
final class MissingKey
{
}
