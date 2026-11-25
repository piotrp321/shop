<?php

declare(strict_types=1);

namespace App\Delivery\UI\Http;

/**
 * The attribute of DeliveryVoter (Infrastructure): the controllers only know its name.
 */
final class DeliveryAttributes
{
    public const string MANAGE_DELIVERY_METHODS = 'MANAGE_DELIVERY_METHODS';
}
