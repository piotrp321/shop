<?php

declare(strict_types=1);

namespace App\Payment\Application;

/**
 * What a provider reports about a payment in a webhook: executed or refused.
 */
enum WebhookOutcome
{
    case Succeeded;
    case Failed;
}
