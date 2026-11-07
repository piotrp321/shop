<?php

declare(strict_types=1);

namespace App\Payment\Application\Query;

use App\Payment\Domain\ProviderCode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Every provider with the name the customer sees, in the order of ProviderCode.
 */
#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListPaymentProvidersHandler
{
    /**
     * @return list<PaymentProviderView>
     */
    public function __invoke(ListPaymentProviders $query): array
    {
        return array_map(static fn (ProviderCode $code): PaymentProviderView => new PaymentProviderView($code->value, $code->displayName()), ProviderCode::cases());
    }
}
