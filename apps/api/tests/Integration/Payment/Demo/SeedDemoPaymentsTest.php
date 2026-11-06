<?php

declare(strict_types=1);

namespace App\Tests\Integration\Payment\Demo;

use App\Payment\Application\Port\PaymentReset;
use App\Payment\Infrastructure\Demo\DbalPaymentReset;
use App\Payment\UI\Console\SeedDemoPaymentsCommand;
use App\Tests\Unit\Payment\PaymentExamples;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * payment:seed-demo on shop_test (rolled back after the test): the payments and the handled webhook events go, and
 * prod refuses without --force-reset.
 */
final class SeedDemoPaymentsTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->connection->executeStatement(
            "INSERT INTO payment_payment (id, order_id, provider, amount, currency, status, idempotency_key, request_fingerprint, created_at) VALUES (?, ?, 'demo-transfer', 100, 'PLN', 'pending', 'seed-test', repeat('a', 64), now())",
            [PaymentExamples::PAYMENT, PaymentExamples::ORDER],
        );
        $this->connection->executeStatement("INSERT INTO payment_webhook_event (provider, event_id, payment_id, received_at) VALUES ('demo-transfer', 'evt_1', ?, now())", [PaymentExamples::PAYMENT]);
    }

    public function testRemovesThePaymentsAndTheWebhookEvents(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('payment:seed-demo'));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('payments removed', $tester->getDisplay());
        self::assertSame([0, 0], $this->rows());
    }

    public function testProdWithoutForceResetRefusesAndChangesNothing(): void
    {
        $tester = $this->tester('prod');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('--force-reset', $tester->getDisplay());
        self::assertSame([1, 1], $this->rows());
    }

    public function testProdWithForceResetRemovesThem(): void
    {
        $tester = $this->tester('prod');

        self::assertSame(Command::SUCCESS, $tester->execute(['--force-reset' => true]), $tester->getDisplay());
        self::assertSame([0, 0], $this->rows());
    }

    public function testADatabaseFailureFailsTheCommandWithoutHidingIt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->willThrowException(new \RuntimeException('database is down'));
        $application = new \Symfony\Component\Console\Application();
        $application->addCommand(new SeedDemoPaymentsCommand(new DbalPaymentReset($connection), 'test'));

        $this->expectExceptionObject(new \RuntimeException('database is down'));

        new CommandTester($application->find('payment:seed-demo'))->execute([]);
    }

    private function tester(string $environment): CommandTester
    {
        $reset = self::getContainer()->get(DbalPaymentReset::class);
        self::assertInstanceOf(PaymentReset::class, $reset);
        $application = new \Symfony\Component\Console\Application();
        $application->addCommand(new SeedDemoPaymentsCommand($reset, $environment));

        return new CommandTester($application->find('payment:seed-demo'));
    }

    /**
     * @return array{mixed, mixed} the payments and the webhook events
     */
    private function rows(): array
    {
        return [
            $this->connection->fetchOne('SELECT COUNT(*) FROM payment_payment'),
            $this->connection->fetchOne('SELECT COUNT(*) FROM payment_webhook_event'),
        ];
    }
}
