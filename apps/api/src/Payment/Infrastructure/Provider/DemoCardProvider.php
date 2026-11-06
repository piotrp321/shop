<?php

declare(strict_types=1);

namespace App\Payment\Infrastructure\Provider;

use App\Payment\Application\Port\PaymentProvider;
use App\Payment\Application\ProviderSession;
use App\Payment\Application\SessionCancellation;
use App\Payment\Domain\Amount;
use App\Payment\Domain\PaymentId;
use App\Payment\Domain\ProviderCode;
use App\Payment\Domain\ProviderPaymentId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "Karta płatnicza (demo)": the API of demo-card (spec: "Port PaymentProvider i adaptery"). HTTP Basic with the user
 * `merchant` and the key, amounts as "123.45" (CardAmount), the expiry in Unix seconds. A session is created with 200
 * `{id, url}` (the customer comes back to the same address after paying or not); its expiry answers 200 `{status:
 * expired}` or, once decided, 409 `{status: complete | failed}`.
 */
final readonly class DemoCardProvider implements PaymentProvider
{
    private const string PATH = '/payment-simulator/demo-card/v1/checkout-sessions';
    private const string USER = 'merchant';

    public function __construct(
        #[Autowire(service: 'app.payment_http_client')]
        private HttpClientInterface $client,
        #[Autowire(env: 'PAYMENT_PROVIDER_BASE_URL')]
        private string $baseUrl,
        #[\SensitiveParameter, Autowire(env: 'PAYMENT_DEMO_CARD_API_KEY')]
        private string $apiKey,
    ) {
    }

    public function code(): ProviderCode
    {
        return ProviderCode::DemoCard;
    }

    public function createSession(PaymentId $paymentId, Amount $amount, string $orderNumber, string $returnUrl, \DateTimeImmutable $expiresAt): ProviderSession
    {
        [$status, $data] = ProviderHttp::post($this->client, $this->code()->value, $this->url(self::PATH), [
            'auth_basic' => [self::USER, $this->apiKey],
            'json' => [
                'reference' => $orderNumber,
                'amount' => CardAmount::format($amount->minorUnits()),
                'currency' => $amount->currency()->value,
                'successUrl' => $returnUrl,
                'cancelUrl' => $returnUrl,
                'expiresAt' => $expiresAt->getTimestamp(),
            ],
        ]);
        if (200 !== $status) {
            throw ProviderHttp::unexpected($this->code()->value, $status);
        }

        return new ProviderSession(
            ProviderHttp::sessionId($data, 'id', $this->code()->value, $status),
            ProviderHttp::pageUrl($data, 'url', $this->code()->value, $status),
        );
    }

    public function cancelSession(ProviderPaymentId $providerPaymentId): SessionCancellation
    {
        [$status, $data] = ProviderHttp::post($this->client, $this->code()->value, $this->url(self::PATH.'/'.rawurlencode($providerPaymentId->toString()).'/expire'), [
            'auth_basic' => [self::USER, $this->apiKey],
        ]);

        return match ([$status, $data['status'] ?? null]) {
            [200, 'expired'] => SessionCancellation::Cancelled,
            [409, 'complete'] => SessionCancellation::AlreadyPaid,
            [409, 'failed'] => SessionCancellation::AlreadyRejected,
            default => throw ProviderHttp::unexpected($this->code()->value, $status),
        };
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
