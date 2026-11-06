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
 * "Karta płatnicza (demo)": the API of demo-card (spec: "Port PaymentProvider i adaptery"). HTTP Basic with the user
 * `merchant` and the key, amounts as "123.45" (CardAmount), the expiry in Unix seconds. A session is created with 200
 * `{id, url}` (the customer comes back to the same address after paying or not); its expiry answers 200 `{status:
 * expired}` or, once decided, 409 `{status: complete | failed}`.
 * Its webhooks carry `Card-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">` (one or more `v1`), valid
 * within 300 s of now either way, and `{id, type, data: {sessionId, reference, amount, currency, completedAt (Unix,
 * with checkout.completed)}}`; a type other than checkout.completed and checkout.failed is ignored.
 */
final readonly class DemoCardProvider implements PaymentProvider
{
    private const string PATH = '/payment-simulator/demo-card/v1/checkout-sessions';
    private const string USER = 'merchant';
    private const string SIGNATURE_HEADER = 'card-signature';
    private const int TOLERANCE_SECONDS = 300;

    public function __construct(
        #[Autowire(service: 'app.payment_http_client')]
        private HttpClientInterface $client,
        #[Autowire(env: 'PAYMENT_PROVIDER_BASE_URL')]
        private string $baseUrl,
        #[\SensitiveParameter, Autowire(env: 'PAYMENT_DEMO_CARD_API_KEY')]
        private string $apiKey,
        #[\SensitiveParameter, Autowire(env: 'PAYMENT_DEMO_CARD_WEBHOOK_SECRET')]
        private string $webhookSecret,
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

    public function parseWebhook(array $headers, string $rawBody, \DateTimeImmutable $now): ?ProviderEvent
    {
        $this->verify($headers[self::SIGNATURE_HEADER] ?? '', $rawBody, $now);

        $event = WebhookFields::object($rawBody);
        $type = WebhookFields::string($event, 'type');
        if (!\in_array($type, ['checkout.completed', 'checkout.failed'], true)) {
            return null;
        }
        $eventId = WebhookFields::eventId($event, 'id');
        $data = WebhookFields::nested($event, 'data');
        $sessionId = WebhookFields::providerPaymentId($data, 'sessionId');
        $amount = WebhookFields::amount(CardAmount::parse(WebhookFields::string($data, 'amount')), $data, 'amount', 'currency');

        return 'checkout.completed' === $type
            ? ProviderEvent::succeeded($eventId, $sessionId, $amount, WebhookFields::unixTime($data, 'completedAt'))
            : ProviderEvent::failed($eventId, $sessionId, $amount);
    }

    /**
     * `t=<unix>,v1=<hex>[,v1=<hex>…]`: a timestamp within the tolerance and one `v1` that matches.
     *
     * @throws InvalidWebhook
     */
    private function verify(string $header, string $rawBody, \DateTimeImmutable $now): void
    {
        if ('' === $header) {
            throw InvalidWebhook::signature('no Card-Signature header');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$name, $value] = explode('=', $part, 2) + [1 => ''];
            if ('t' === $name && null === $timestamp && 1 === preg_match('/\A[1-9][0-9]{0,11}\z/', $value)) {
                $timestamp = (int) $value;
            } elseif ('v1' === $name) {
                $signatures[] = $value;
            }
        }
        if (null === $timestamp || [] === $signatures) {
            throw InvalidWebhook::signature('Card-Signature has no timestamp or no v1');
        }
        if (abs($now->getTimestamp() - $timestamp) > self::TOLERANCE_SECONDS) {
            throw InvalidWebhook::signature('the timestamp of Card-Signature is outside the tolerance of 300 s');
        }
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->webhookSecret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        throw InvalidWebhook::signature('no v1 of Card-Signature matches the body');
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
