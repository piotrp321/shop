<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

/**
 * The shop did not accept the webhook (a 5xx or an unexpected status): the transport retries the delivery.
 */
final class WebhookDeliveryFailed extends \RuntimeException
{
}
