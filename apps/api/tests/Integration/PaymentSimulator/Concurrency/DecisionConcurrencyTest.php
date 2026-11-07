<?php

declare(strict_types=1);

namespace App\Tests\Integration\PaymentSimulator\Concurrency;

use App\PaymentSimulator\CancelTransaction;
use App\PaymentSimulator\DecideTransaction;
use App\PaymentSimulator\Provider;
use App\Tests\Integration\Inventory\Concurrency\ConcurrentCommands;
use App\Tests\Integration\Inventory\Concurrency\Outcome;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The decision of the customer and the cancellation by the shop on the same transaction, as separate processes on
 * separate connections that start at the same moment (ConcurrentCommands of the Inventory tests), 5 rounds each, with
 * the real Doctrine transport `payment_simulator` (kernel `test_messaging`): the row lock makes them run one after
 * the other, so the transaction ends in exactly one final state and the deliveries match it. The rows commit, so they
 * are deleted after the test.
 */
final class DecisionConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;
    private const string ENVIRONMENT = 'test_messaging';

    private Connection $control;
    /** @var list<string> */
    private array $transactions = [];

    protected function setUp(): void
    {
        // Boots the kernel, so its cache is warm before the participants boot theirs.
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $this->control->executeStatement("SET lock_timeout = '10s'");
        $this->control->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'payment_simulator'");
    }

    protected function tearDown(): void
    {
        $this->control->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'payment_simulator'");
        if ([] !== $this->transactions) {
            $this->control->executeStatement('DELETE FROM payment_simulator_transaction WHERE id IN (?)', [$this->transactions], [ArrayParameterType::STRING]);
        }
        $this->control->close();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{Provider}>
     */
    public static function providers(): iterable
    {
        yield 'demo-transfer' => [Provider::DemoTransfer];
        yield 'demo-card' => [Provider::DemoCard];
    }

    #[DataProvider('providers')]
    public function testCancelAndPayRunInParallelAndLeaveExactlyOneFinalState(Provider $provider): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $id = $this->transaction($provider);
            $this->control->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'payment_simulator'");

            $outcomes = ConcurrentCommands::run($this->control, [
                new DecideTransaction($id, $provider, true),
                new CancelTransaction($id, $provider),
            ], self::ENVIRONMENT);

            $message = $provider->value.' round '.$round.': '.json_encode($outcomes, \JSON_PARTIAL_OUTPUT_ON_ERROR);
            [$decision, $cancellation] = $outcomes;
            self::assertSame(Outcome::SUCCESS, $decision->kind, $message);
            self::assertSame(Outcome::SUCCESS, $cancellation->kind, $message);
            $status = $this->control->fetchOne('SELECT status FROM payment_simulator_transaction WHERE id = ?', [$id]);
            if ('paid' === $status) {
                self::assertTrue($decision->result['applied'] ?? null, $message);
                self::assertSame('paid', $cancellation->result['result'] ?? null, 'the cancellation saw the payment and was refused: '.$message);
                self::assertSame(1, $this->deliveries(), $message);
            } else {
                self::assertSame('cancelled', $status, $message);
                self::assertFalse($decision->result['applied'] ?? null, $message);
                self::assertSame('cancelled', $cancellation->result['result'] ?? null, $message);
                self::assertSame(0, $this->deliveries(), 'a cancelled session is never paid and delivers nothing: '.$message);
            }
        }
    }

    public function testTenDecisionsInParallelApplyOneAndDeliverOnce(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $id = $this->transaction(Provider::DemoTransfer);
            $this->control->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'payment_simulator'");
            $commands = [];
            for ($i = 0; $i < 10; ++$i) {
                $commands[] = new DecideTransaction($id, Provider::DemoTransfer, 0 === $i % 2);
            }

            $outcomes = ConcurrentCommands::run($this->control, $commands, self::ENVIRONMENT);

            $message = 'round '.$round;
            $applied = array_filter($outcomes, static fn (Outcome $outcome): bool => true === ($outcome->result['applied'] ?? null));
            self::assertCount(1, $applied, $message);
            self::assertContains($this->control->fetchOne('SELECT status FROM payment_simulator_transaction WHERE id = ?', [$id]), ['paid', 'rejected'], $message);
            self::assertSame(1, $this->deliveries(), $message);
        }
    }

    private function transaction(Provider $provider): string
    {
        $id = Uuid::v4()->toRfc4122();
        $transfer = Provider::DemoTransfer === $provider;
        $this->control->executeStatement(
            "INSERT INTO payment_simulator_transaction (id, provider, reference, amount, currency, return_url, success_url, cancel_url, expires_at, status, created_at) VALUES (?, ?, 'R-100001', 12345, 'PLN', ?, ?, ?, now() + interval '30 minutes', 'open', now())",
            [$id, $provider->value, $transfer ? 'https://shop.test/back' : null, $transfer ? null : 'https://shop.test/ok', $transfer ? null : 'https://shop.test/cancel'],
        );
        $this->transactions[] = $id;

        return $id;
    }

    private function deliveries(): int
    {
        $count = $this->control->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'payment_simulator'");
        self::assertIsInt($count);

        return $count;
    }
}
