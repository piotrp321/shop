<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

/**
 * The two fake providers; the value is their code in URLs and in the webhook path of the shop.
 */
enum Provider: string
{
    case DemoTransfer = 'demo-transfer';
    case DemoCard = 'demo-card';

    public function label(): string
    {
        return match ($this) {
            self::DemoTransfer => 'Szybki przelew (demo)',
            self::DemoCard => 'Karta płatnicza (demo)',
        };
    }

    /** The endpoint of the shop that receives the webhooks of this provider (relative to PAYMENT_WEBHOOK_BASE_URL). */
    public function webhookPath(): string
    {
        return '/api/v1/payments/webhooks/'.$this->value;
    }
}
