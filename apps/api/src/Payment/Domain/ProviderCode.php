<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * The payment providers the shop offers, in the order the store lists them, with the names the customer sees.
 */
enum ProviderCode: string
{
    case DemoTransfer = 'demo-transfer';
    case DemoCard = 'demo-card';

    /**
     * @throws InvalidPaymentInput
     */
    public static function fromString(string $code): self
    {
        return self::tryFrom($code) ?? throw InvalidPaymentInput::provider();
    }

    public function displayName(): string
    {
        return match ($this) {
            self::DemoTransfer => 'Szybki przelew (demo)',
            self::DemoCard => 'Karta płatnicza (demo)',
        };
    }
}
