<?php

declare(strict_types=1);

namespace App\Delivery\Application\Query;

/**
 * Every delivery method of the panel, without pages (at most 20 are active; the inactive ones are kept, P16):
 * answered with a list of DeliveryMethodView in the order of DeliveryMethodOrder.
 */
final readonly class ListDeliveryMethods
{
}
