<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Delivers the signed webhook of a decided transaction to the shop (PAYMENT_WEBHOOK_BASE_URL + the webhook path of the
 * provider). Any 2xx and any 4xx end the delivery (a 4xx is logged: the shop refused the webhook and a retry would be
 * refused again). A 5xx, any other status and a network error throw, so the transport retries the message: the event
 * id is the same each time, the receiver deduplicates, and a demo-card signature is made again with the time of the
 * attempt. A request waits 5 seconds at most for the shop (a timeout is a network error). A transaction that is gone
 * (the demo data was reset) or has no decision needs no delivery.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeliverSimulatedWebhookHandler
{
    /** A shop that stays silent must not hold the worker, which serves the other queues one after another, for the default minute. */
    private const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SimulatedWebhooks $webhooks,
        #[Autowire(service: 'app.payment_http_client')]
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire(env: 'PAYMENT_WEBHOOK_BASE_URL')]
        private string $webhookBaseUrl,
    ) {
    }

    public function __invoke(DeliverSimulatedWebhook $message): void
    {
        $transaction = $this->entityManager->find(Transaction::class, $message->transactionId);
        if (!$transaction instanceof Transaction || !\in_array($transaction->status, [TransactionStatus::Paid, TransactionStatus::Rejected], true)) {
            $this->logger->warning('Payment simulator: no decided transaction to deliver, the delivery is dropped.', ['transactionId' => $message->transactionId]);

            return;
        }

        $webhook = $this->webhooks->build($transaction, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $status = $this->httpClient->request('POST', rtrim($this->webhookBaseUrl, '/').$webhook->path, [
            'headers' => $webhook->headers,
            'body' => $webhook->body,
            'timeout' => self::TIMEOUT_SECONDS,
        ])->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return;
        }
        if ($status >= 400 && $status < 500) {
            $this->logger->warning('Payment simulator: the shop refused the webhook, the delivery ends.', [
                'transactionId' => $transaction->id,
                'provider' => $transaction->provider->value,
                'status' => $status,
            ]);

            return;
        }

        throw new WebhookDeliveryFailed(\sprintf('The shop answered %d to the webhook of the transaction %s.', $status, $transaction->id));
    }
}
