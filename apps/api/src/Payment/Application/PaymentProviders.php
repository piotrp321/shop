<?php

declare(strict_types=1);

namespace App\Payment\Application;

use App\Payment\Application\Port\PaymentProvider;
use App\Payment\Domain\ProviderCode;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The adapters of the providers by their code.
 */
final readonly class PaymentProviders
{
    /** @var array<string, PaymentProvider> */
    private array $providers;

    /**
     * @param iterable<PaymentProvider> $providers
     */
    public function __construct(#[AutowireIterator(PaymentProvider::TAG)] iterable $providers)
    {
        $byCode = [];
        foreach ($providers as $provider) {
            $byCode[$provider->code()->value] = $provider;
        }
        $this->providers = $byCode;
    }

    /**
     * @throws \LogicException no adapter for a known code (a wiring error)
     */
    public function get(ProviderCode $code): PaymentProvider
    {
        return $this->providers[$code->value] ?? throw new \LogicException('No adapter of the provider '.$code->value.'.');
    }
}
