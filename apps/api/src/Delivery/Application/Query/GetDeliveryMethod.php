<?php

declare(strict_types=1);

namespace App\Delivery\Application\Query;

/**
 * One delivery method of the panel; answered with a DeliveryMethodView.
 */
final readonly class GetDeliveryMethod
{
    /**
     * @param string $id any text; one that is no UUID is an unknown method
     */
    public function __construct(public string $id)
    {
    }
}
