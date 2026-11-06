<?php

declare(strict_types=1);

namespace App\Payment\Domain;

/**
 * The id of a webhook event of a provider, the key of the deduplication of its deliveries.
 */
final readonly class ProviderEventId
{
    private function __construct(private string $value)
    {
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        return new self(ProviderIdFormat::check($value, 'ProviderEventId'));
    }

    public function toString(): string
    {
        return $this->value;
    }
}
