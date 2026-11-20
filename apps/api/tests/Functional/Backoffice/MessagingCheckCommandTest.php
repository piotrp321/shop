<?php

declare(strict_types=1);

namespace App\Tests\Functional\Backoffice;

use App\Backoffice\Application\Query\MessagingHealth;
use App\Backoffice\Infrastructure\MessagingStatisticsAdapter;
use App\Backoffice\UI\Console\MessagingCheckCommand;
use App\Tests\Support\MessengerRows;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * app:messaging:check (spec: docs/features/async-reliability/spec.md, W7, criterion 4) from the console of the kernel, on
 * the real table of the messages: the CronJob queue-check of the chart runs it, and the alert MessagingUnhealthy fires
 * when its Job fails.
 */
final class MessagingCheckCommandTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        MessengerRows::clear($this->connection);
    }

    public function testEmptyQueuesAreHealthy(): void
    {
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertSame(
            [
                'failed: 0',
                'outbox: ready 0',
                'scheduled: ready 0',
                'payment_simulator: ready 0',
                'promotions: ready 0',
                'jobs: ready 0',
            ],
            $this->lines($tester),
        );
    }

    public function testAFailedMessageIsUnhealthyWhateverElseIsFine(): void
    {
        MessengerRows::add($this->connection, 'failed', new \DateTimeImmutable('-2 hours'), new \DateTimeImmutable('-3 hours'));
        $tester = $this->tester();

        self::assertSame(1, $tester->execute([]), $tester->getDisplay());
        self::assertMatchesRegularExpression('/^failed: 1, the oldest since \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $this->lines($tester)[0]);
    }

    public function testManyFailedMessagesAreReportedAsAtLeast1000(): void
    {
        MessengerRows::add($this->connection, 'failed', times: 1001);
        $tester = $this->tester();

        self::assertSame(1, $tester->execute([]));
        self::assertStringStartsWith('failed: 1000+, the oldest since ', $this->lines($tester)[0]);
    }

    public function testAReadyMessageOlderThanTheLimitIsAQueueThatStands(): void
    {
        MessengerRows::add($this->connection, 'scheduled', new \DateTimeImmutable('-11 minutes'));
        $tester = $this->tester();

        self::assertSame(1, $tester->execute([]), $tester->getDisplay());
        $lines = $this->lines($tester);
        self::assertSame('failed: 0', $lines[0]);
        self::assertMatchesRegularExpression('/^scheduled: ready 1, the oldest 6\d\d s, STANDS$/', $lines[2]);
    }

    public function testAReadyMessageYoungerThanTheLimitIsHealthy(): void
    {
        MessengerRows::add($this->connection, 'jobs', new \DateTimeImmutable('-9 minutes'));
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertMatchesRegularExpression('/^jobs: ready 1, the oldest 5\d\d s$/', $this->lines($tester)[5]);
    }

    public function testADelayedMessageThatHasJustBecomeReadyDoesNotStand(): void
    {
        // ExpireUnpaidOrder: written 35 minutes ago, due since a few seconds.
        MessengerRows::add($this->connection, 'scheduled', new \DateTimeImmutable('-3 seconds'), new \DateTimeImmutable('-35 minutes'));
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringNotContainsString('STANDS', $tester->getDisplay());
    }

    public function testAMessageDueInTheFutureIsNotReady(): void
    {
        MessengerRows::add($this->connection, 'scheduled', new \DateTimeImmutable('+25 minutes'), new \DateTimeImmutable('-35 minutes'));
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertSame('scheduled: ready 0', $this->lines($tester)[2]);
    }

    public function testAMessageTakenByAWorkerThatDiedIsAQueueThatStandsOnceTheTimeoutHasPassed(): void
    {
        // Taken an hour ago by a worker that died before the acknowledgement, due for 11 minutes before that: Messenger
        // hands it out again after `redeliver_timeout`, and nobody takes it.
        MessengerRows::add($this->connection, 'jobs', new \DateTimeImmutable('-71 minutes'), deliveredAt: new \DateTimeImmutable('-1 hour'));
        $tester = $this->tester();

        self::assertSame(1, $tester->execute([]), $tester->getDisplay());
        self::assertMatchesRegularExpression('/^jobs: ready 1, the oldest 4\d\d\d s, STANDS$/', $this->lines($tester)[5]);
    }

    public function testAMessageThatAWorkerHasJustTakenIsNotReady(): void
    {
        MessengerRows::add($this->connection, 'jobs', new \DateTimeImmutable('-71 minutes'), deliveredAt: new \DateTimeImmutable('-10 seconds'));
        $tester = $this->tester();

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertSame('jobs: ready 0', $this->lines($tester)[5]);
    }

    public function testTheOutputHasNoContentOfTheMessages(): void
    {
        MessengerRows::add($this->connection, 'failed', body: 'O:11:"SecretOrder":1:{s:5:"email";s:21:"jan.kowalski@test.pl";}');
        MessengerRows::add($this->connection, 'jobs', new \DateTimeImmutable('-30 minutes'), body: 'O:11:"SecretOrder":1:{s:5:"email";s:21:"jan.kowalski@test.pl";}');
        $tester = $this->tester();

        self::assertSame(1, $tester->execute([]));
        self::assertStringNotContainsString('SecretOrder', $tester->getDisplay());
        self::assertStringNotContainsString('jan.kowalski', $tester->getDisplay());
    }

    public function testAnUnavailableDatabaseIsExitCodeTwoWithTheReason(): void
    {
        $unreachable = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 1, 'dbname' => 'shop', 'user' => 'x', 'password' => 'x', 'connect_timeout' => 2]);
        $tester = new CommandTester(new MessagingCheckCommand(new MessagingHealth($this->adapter($unreachable), 600)));

        self::assertSame(2, $tester->execute([]));
        self::assertStringContainsString('The messages could not be read:', $tester->getDisplay());
    }

    public function testTheBrokerIsNeverCalled(): void
    {
        MessengerRows::add($this->connection, 'failed');
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): never {
            ++$requests;
            self::fail('app:messaging:check must work while RabbitMQ is down.');
        });
        $tester = new CommandTester(new MessagingCheckCommand(new MessagingHealth(
            new MessagingStatisticsAdapter($this->connection, $client, 'http://rabbitmq.invalid:15672', 'user', 'secret', 'test', new Clock(), MessengerRows::redeliverTimeouts(self::getContainer())),
            600,
        )));

        $tester->execute([]);

        self::assertSame(0, $requests);
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('app:messaging:check'));
    }

    private function adapter(Connection $connection): MessagingStatisticsAdapter
    {
        return new MessagingStatisticsAdapter($connection, new MockHttpClient(), 'http://rabbitmq.invalid:15672', 'user', 'secret', 'test', new Clock(), MessengerRows::redeliverTimeouts(self::getContainer()));
    }

    /**
     * @return list<string>
     */
    private function lines(CommandTester $tester): array
    {
        return array_values(array_filter(array_map('rtrim', explode("\n", $tester->getDisplay())), static fn (string $line): bool => '' !== $line));
    }
}
