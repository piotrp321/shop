<?php

declare(strict_types=1);

namespace App\Tests\Integration\PaymentSimulator\Demo;

use App\PaymentSimulator\Console\SeedDemoCommand;
use App\Tests\Support\SimulatorTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * payment-simulator:seed-demo on shop_test (rolled back after the test): the transactions of the simulator and its
 * queue `payment_simulator` go, the other queues stay, and prod refuses without --force-reset.
 */
final class SeedDemoTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        SimulatorTransactions::transfer($entityManager);
        SimulatorTransactions::card($entityManager);
        foreach (['payment_simulator', 'payment_simulator', 'scheduled', 'outbox', 'failed', 'events'] as $queue) {
            $this->connection->executeStatement(
                "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('{}', '{}', ?, now(), now() + interval '35 minutes')",
                [$queue],
            );
        }
    }

    public function testRemovesTheTransactionsAndOnlyItsOwnQueue(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame(0, $this->transactions());
        self::assertSame(
            ['events' => 1, 'failed' => 1, 'outbox' => 1, 'scheduled' => 1],
            $this->queues(),
            'the deliveries of the simulator are gone, every other queue stays',
        );
    }

    public function testRegisteredInTheApplicationWithTheNameOfMakeSeed(): void
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('payment-simulator:seed-demo'));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
        self::assertSame(0, $this->transactions());
    }

    public function testProdWithoutForceResetRefusesAndChangesNothing(): void
    {
        $tester = $this->tester('prod');

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('--force-reset', $tester->getDisplay());
        self::assertSame(2, $this->transactions());
        self::assertSame(['events' => 1, 'failed' => 1, 'outbox' => 1, 'payment_simulator' => 2, 'scheduled' => 1], $this->queues());
    }

    public function testProdWithForceResetRemovesThem(): void
    {
        $tester = $this->tester('prod');

        self::assertSame(Command::SUCCESS, $tester->execute(['--force-reset' => true]), $tester->getDisplay());
        self::assertSame(0, $this->transactions());
        self::assertArrayNotHasKey('payment_simulator', $this->queues());
    }

    public function testADatabaseFailureRollsBackAndFailsTheCommandWithoutHidingIt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('database is down'));
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');
        $application = new \Symfony\Component\Console\Application();
        $application->addCommand(new SeedDemoCommand($connection, 'test'));
        $tester = new CommandTester($application->find('payment-simulator:seed-demo'));

        $this->expectExceptionObject(new \RuntimeException('database is down'));

        $tester->execute([]);
    }

    private function tester(string $environment = 'test'): CommandTester
    {
        $application = new \Symfony\Component\Console\Application();
        $application->addCommand(new SeedDemoCommand($this->connection, $environment));

        return new CommandTester($application->find('payment-simulator:seed-demo'));
    }

    private function transactions(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM payment_simulator_transaction');
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return array<mixed>
     */
    private function queues(): array
    {
        return $this->connection->fetchAllKeyValue('SELECT queue_name, COUNT(*) FROM messenger_messages GROUP BY queue_name ORDER BY queue_name');
    }
}
