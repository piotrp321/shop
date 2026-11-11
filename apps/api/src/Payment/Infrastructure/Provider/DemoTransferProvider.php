<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Provider;

use App\Payment\Application\InvalidWebhook;
use App\Payment\Application\Port\PaymentProvider;
use App\Payment\Application\ProviderEvent;
use App\Payment\Application\ProviderSession;
use App\Payment\Application\SessionCancellation;
use App\Payment\Domain\Amount;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderPaymentId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "Szybki przelew (demo)": the API of demo-transfer (spec: "Port PaymentProvider i adaptery"). `Authorization: Bearer
 * <key>`, amounts in minor units, times in RFC 3339 (UTC). A transaction is created with 201 `{transactionId,
 * paymentUrl}`; its cancellation answers 200 `{status: CANCELLED}` or, once decided, 409 `{status: PAID | REJECTED}`.
 * Its webhooks carry `X-Signature`, the hex HMAC-SHA256 of the raw body with the webhook secret, and `{eventId,
 * transactionId, merchantRef, status: PAID | REJECTED, amount, currency, paidAt (RFC 3339, with PAID)}`. An empty webhook
 * secret refuses every webhook before the signature is read.
 */
final readonly class DemoTransferProvider implements PaymentProvider
{
    private const string PATH = '/payment-simulator/demo-transfer/api/transactions';
    private const string SIGNATURE_HEADER = 'x-signature';

    public function __construct(
        #[Autowire(service: 'app.payment_http_client')]
        private HttpClientInterface $client,
        #[Autowire(env: 'PAYMENT_PROVIDER_BASE_URL')]
        private string $baseUrl,
        #[\SensitiveParameter, Autowire(env: 'PAYMENT_DEMO_TRANSFER_API_KEY')]
        private string $apiKey,
        #[\SensitiveParameter, Autowire(env: 'PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET')]
        private string $webhookSecret,
    ) {
    }

    public function code(): ProviderCode
    {
        return ProviderCode::DemoTransfer;
    }

    public function createSession(PaymentId $paymentId, Amount $amount, string $orderNumber, string $returnUrl, \DateTimeImmutable $expiresAt): ProviderSession
    {
        [$status, $data] = ProviderHttp::post($this->client, $this->code()->value, $this->url(self::PATH), [
            'auth_bearer' => $this->apiKey,
            'json' => [
                'merchantRef' => $orderNumber,
                'amount' => $amount->minorUnits(),
                'currency' => $amount->currency()->value,
                'description' => 'Zamówienie '.$orderNumber,
                'returnUrl' => $returnUrl,
                'expiresAt' => $expiresAt->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_RFC3339),
            ],
        ]);
        if (201 !== $status) {
            throw ProviderHttp::unexpected($this->code()->value, $status);
        }

        return new ProviderSession(
            ProviderHttp::sessionId($data, 'transactionId', $this->code()->value, $status),
            ProviderHttp::pageUrl($data, 'paymentUrl', $this->code()->value, $status),
        );
    }

    public function cancelSession(ProviderPaymentId $providerPaymentId): SessionCancellation
    {
        [$status, $data] = ProviderHttp::post($this->client, $this->code()->value, $this->url(self::PATH.'/'.rawurlencode($providerPaymentId->toString()).'/cancel'), [
            'auth_bearer' => $this->apiKey,
        ]);

        return match ([$status, $data['status'] ?? null]) {
            [200, 'CANCELLED'] => SessionCancellation::Cancelled,
            [409, 'PAID'] => SessionCancellation::AlreadyPaid,
            [409, 'REJECTED'] => SessionCancellation::AlreadyRejected,
            default => throw ProviderHttp::unexpected($this->code()->value, $status),
        };
    }

    public function parseWebhook(array $headers, string $rawBody, \DateTimeImmutable $now): ProviderEvent
    {
        if ('' === $this->webhookSecret) {
            throw InvalidWebhook::emptySecret();
        }
        $signature = $headers[self::SIGNATURE_HEADER] ?? '';
        if ('' === $signature) {
            throw InvalidWebhook::signature('no X-Signature header');
        }
        if (!hash_equals(hash_hmac('sha256', $rawBody, $this->webhookSecret), $signature)) {
            throw InvalidWebhook::signature('X-Signature does not match the body');
        }

        $data = WebhookFields::object($rawBody);
        $eventId = WebhookFields::eventId($data, 'eventId');
        $transactionId = WebhookFields::providerPaymentId($data, 'transactionId');
        $minorUnits = $data['amount'] ?? null;
        $amount = WebhookFields::amount(\is_int($minorUnits) ? $minorUnits : null, $data, 'amount', 'currency');

        return match (WebhookFields::string($data, 'status')) {
            'PAID' => ProviderEvent::succeeded($eventId, $transactionId, $amount, WebhookFields::rfc3339($data, 'paidAt')),
            'REJECTED' => ProviderEvent::failed($eventId, $transactionId, $amount),
            default => throw InvalidWebhook::body('status'),
        };
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
