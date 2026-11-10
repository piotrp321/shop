<?php

declare(strict_types=1);

namespace App\Payment\UI\Http;

use App\Payment\Application\Command\HandleProviderWebhook;
use App\Payment\Application\InvalidWebhook;
use App\Payment\Application\PaymentProviders;
use App\Payment\Application\Port\Clock;
use App\Payment\Domain\ProviderCode;
use App\Shared\Infrastructure\Http\RateLimit;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The webhooks of the payment providers, without a token (they are signed). The adapter of the provider verifies the
 * signature and reads the body, as the payload mapping of other endpoints does: an unknown provider is a 404, an
 * invalid webhook a 400 with one fixed text for every cause (the cause goes to the log), an event of a type the shop
 * does not handle a 204 without a command. HandleProviderWebhook does the rest. `private, no-store`
 * (PaymentResponseCacheSubscriber).
 */
final readonly class WebhookController
{
    public function __construct(
        private PaymentProviders $providers,
        private Clock $clock,
        #[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/v1/payments/webhooks/{provider}', name: 'payment_webhook', methods: ['POST'], defaults: [RateLimit::ROUTE_DEFAULT => RateLimit::PAYMENT_WEBHOOK, RateLimit::KEY_ROUTE_DEFAULT => RateLimit::BY_PROVIDER])]
    #[OA\Post(
        operationId: 'receivePaymentWebhook',
        summary: 'Webhook of a payment provider',
        description: 'Called by the provider (not by the store) when a payment session is paid or refused. The signature of the raw body is '
            .'verified first: `demo-transfer` sends `X-Signature` (hex HMAC-SHA256 of the body), `demo-card` sends `Card-Signature: '
            .'t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<body>">` valid for 300 s. Each event is handled once (by its id); a repeated delivery '
            .'answers 204 and changes nothing. A succeeded payment pays its order when it was executed before the payment deadline; '
            .'otherwise (or for a cancelled order, or an order paid by another payment) it is kept for a refund by hand.',
        tags: ['Payment'],
        parameters: [
            new OA\Parameter(name: 'provider', in: 'path', required: true, description: 'Code of the provider.', schema: new OA\Schema(type: 'string', enum: ['demo-transfer', 'demo-card'])),
            new OA\Parameter(name: 'X-Signature', in: 'header', required: false, description: '`demo-transfer`: hex HMAC-SHA256 of the raw body with the webhook secret.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'Card-Signature', in: 'header', required: false, description: '`demo-card`: `t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`.', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            description: 'The event as the provider sends it. `demo-transfer`: `{eventId, transactionId, merchantRef, status: PAID | REJECTED, '
                .'amount (grosze), currency, paidAt (RFC 3339, with PAID)}`. `demo-card`: `{id, type: checkout.completed | checkout.failed, '
                .'data: {sessionId, reference, amount ("123.45"), currency, completedAt (Unix seconds, with checkout.completed)}}`; other types '
                .'are acknowledged and ignored.',
            content: new OA\JsonContent(type: 'object'),
        ),
        responses: [
            new OA\Response(response: 204, description: 'The event was handled, was handled before, or is of a type the shop ignores.'),
            new OA\Response(response: 400, description: 'Missing or wrong signature, a timestamp outside the tolerance, or a body that breaks the format of the provider (problem details, one text for every cause).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 401, description: 'An access token of a session was sent and is invalid (problem details, `WWW-Authenticate: Bearer`). None is needed.', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 404, description: 'Unknown provider, or no payment of the session at this provider (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 422, description: 'The amount is not the amount of the payment; nothing changed (problem details).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 429, description: 'Too many webhooks of this provider from this address: the rate limit of the webhooks, before the signature is verified. Nothing is handled; the provider retries later. Problem details; `Retry-After` tells the seconds to wait.', headers: [new OA\Header(header: 'Retry-After', description: 'Seconds to wait before the next attempt.', schema: new OA\Schema(type: 'integer', minimum: 1))], content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
            new OA\Response(response: 503, description: 'The order changed concurrently; nothing changed and the provider retries (problem details without detail).', content: new OA\MediaType(mediaType: 'application/problem+json', schema: new OA\Schema(ref: '#/components/schemas/Problem'))),
        ],
    )]
    public function __invoke(Request $request, string $provider): Response
    {
        $code = ProviderCode::tryFrom($provider) ?? throw new NotFoundHttpException('Unknown payment provider.');
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }

        try {
            $event = $this->providers->get($code)->parseWebhook($headers, $request->getContent(), $this->clock->now());
        } catch (InvalidWebhook $invalid) {
            $this->logger->warning('Payment webhook refused.', ['provider' => $code->value, 'cause' => $invalid->getMessage()]);

            throw new BadRequestHttpException('Invalid webhook.', $invalid);
        }

        if (null !== $event) {
            $this->commandBus->dispatch(new HandleProviderWebhook($code, $event));
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
