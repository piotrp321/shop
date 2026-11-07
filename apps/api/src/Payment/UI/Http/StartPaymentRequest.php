<?php

declare(strict_types=1);

namespace App\Payment\UI\Http;

use App\Payment\Domain\ProviderCode;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of a start of a payment: only the provider. The amount and the return address come from the API, never from
 * the body; unknown keys are ignored. The serializer gives `null` for a missing key (the property is `mixed`); the
 * value is taken as JSON gives it, never cast, and gives at most one violation, in Polish.
 */
#[OA\Schema(description: 'The provider the customer chose.', required: ['provider'])]
final readonly class StartPaymentRequest
{
    public function __construct(
        #[OA\Property(type: 'string', enum: ['demo-transfer', 'demo-card'], description: 'Code of the provider, one of `GET /api/v1/payment-providers`.', example: 'demo-transfer')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Wybierz sposób płatności.'),
            new Assert\Type('string', message: 'Nieznany sposób płatności.'),
            new Assert\NotBlank(message: 'Wybierz sposób płatności.'),
            new Assert\Choice(callback: [self::class, 'providers'], message: 'Nieznany sposób płatności.'),
        ])]
        public mixed $provider,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function providers(): array
    {
        return array_map(static fn (ProviderCode $code): string => $code->value, ProviderCode::cases());
    }
}
