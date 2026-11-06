<?php

declare(strict_types=1);

namespace App\Payment\UI\Http;

use App\Payment\Application\Query\ListPaymentProviders;
use App\Payment\Application\Query\PaymentProviderView;
use OpenApi\Attributes as OA;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The providers the checkout offers, without a token. `private, no-store` (PaymentResponseCacheSubscriber).
 */
final readonly class PaymentProvidersController
{
    public function __construct(#[Autowire(service: 'query.bus')] private MessageBusInterface $queryBus)
    {
    }

    #[Route('/api/v1/payment-providers', name: 'payment_providers', methods: ['GET'])]
    #[OA\Get(
        operationId: 'listPaymentProviders',
        summary: 'Payment providers',
        description: 'Every provider a customer can choose in the checkout, with the name to show, in the order to show them.',
        tags: ['Payment'],
        responses: [
            new OA\Response(response: 200, description: 'The providers.', content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: '#/components/schemas/PaymentProvider'))),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        $providers = $this->queryBus->dispatch(new ListPaymentProviders())->last(HandledStamp::class)?->getResult();
        \assert(\is_array($providers));

        return new JsonResponse(array_map(static function (mixed $provider): array {
            \assert($provider instanceof PaymentProviderView);

            return ['code' => $provider->code, 'name' => $provider->name];
        }, $providers));
    }
}
