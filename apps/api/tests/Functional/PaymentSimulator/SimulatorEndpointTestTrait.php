<?php

declare(strict_types=1);

namespace App\Tests\Functional\PaymentSimulator;

use App\PaymentSimulator\Provider;
use App\PaymentSimulator\Transaction;
use App\Tests\Support\SimulatorTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Helpers of the functional tests of the simulator: one kernel for the whole test (no reboot between requests),
 * transactions stored through the EntityManager, the state read in SQL, the keys and secrets from the environment
 * (.env.local through the compose file), the messages of the in-memory `payment_simulator` transport.
 */
trait SimulatorEndpointTestTrait
{
    private KernelBrowser $client;

    private function startClient(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private function connection(): Connection
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function transfer(string $reference = 'R-100001', string $expiresAt = '+30 minutes'): Transaction
    {
        return SimulatorTransactions::transfer($this->entityManager(), $reference, 12_345, $expiresAt);
    }

    private function card(string $reference = 'R-100001', string $expiresAt = '+30 minutes'): Transaction
    {
        return SimulatorTransactions::card($this->entityManager(), $reference, 12_345, $expiresAt);
    }

    /**
     * @return array{string, bool} the status and whether the decision time is set, read past the identity map
     */
    private function state(string $id): array
    {
        $row = $this->connection()->fetchAssociative('SELECT status, decided_at IS NOT NULL AS decided FROM payment_simulator_transaction WHERE id = ?', [$id]);
        self::assertIsArray($row);
        self::assertIsString($row['status']);

        return [$row['status'], (bool) $row['decided']];
    }

    private function countTransactions(): int
    {
        $count = $this->connection()->fetchOne('SELECT count(*) FROM payment_simulator_transaction');
        self::assertIsInt($count);

        return $count;
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        self::assertIsString($value, $name.' is not set: is the stack up with the PAYMENT_* variables (make up)?');
        self::assertNotSame('', $value);

        return $value;
    }

    private function apiKey(Provider $provider): string
    {
        return $this->env(Provider::DemoTransfer === $provider ? 'PAYMENT_DEMO_TRANSFER_API_KEY' : 'PAYMENT_DEMO_CARD_API_KEY');
    }

    private function webhookSecret(Provider $provider): string
    {
        return $this->env(Provider::DemoTransfer === $provider ? 'PAYMENT_DEMO_TRANSFER_WEBHOOK_SECRET' : 'PAYMENT_DEMO_CARD_WEBHOOK_SECRET');
    }

    private function csrfToken(string $transactionId, Provider $provider): string
    {
        return hash_hmac('sha256', $transactionId, $this->webhookSecret($provider));
    }

    /**
     * @return list<object> the messages the request sent to the transport of the webhook deliveries
     */
    private function sentDeliveries(): array
    {
        $transport = static::getContainer()->get('messenger.transport.payment_simulator');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()));
    }

    /**
     * @return array<mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
