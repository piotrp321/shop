<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Shared\Infrastructure\Messaging\FailedMessageQueue;
use App\Shared\UI\Console\FailedRetryCommand;
use App\Tests\Support\Messaging\ScriptedOutcome;
use App\Tests\Support\Messaging\ScriptedOutcomeHandler;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application as FrameworkApplication;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

/**
 * app:messaging:failed-retry (spec: docs/features/async-reliability/spec.md, W12, criterion 14): the bulk retry of the
 * messages of `failed` that keeps the ones that fail again, on the real Doctrine transport of the `test_messaging` kernel.
 */
final class FailedRetryCommandTest extends MessagingKernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ScriptedOutcomeHandler::reset();
        $this->connection()->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'failed'");
    }

    public function testEachSelectedMessageIsTriedOnceAndTheReportCountsWhatHappened(): void
    {
        ScriptedOutcomeHandler::script('fails', ScriptedOutcomeHandler::ERROR);
        ScriptedOutcomeHandler::script('fails-unrecoverably', ScriptedOutcomeHandler::UNRECOVERABLE);
        ScriptedOutcomeHandler::script('waits', ScriptedOutcomeHandler::RECOVERABLE);
        $fails = $this->putInFailed('fails');
        $succeeds = $this->putInFailed('succeeds');
        $failsUnrecoverably = $this->putInFailed('fails-unrecoverably');
        $waits = $this->putInFailed('waits');
        $succeedsToo = $this->putInFailed('succeeds-too');

        $tester = $this->retryAll([]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        foreach (['fails', 'succeeds', 'fails-unrecoverably', 'waits', 'succeeds-too'] as $key) {
            self::assertSame(1, ScriptedOutcomeHandler::attempts($key), $key.' is tried exactly once, its kept copy is not tried again');
        }
        $left = $this->failedIds();
        self::assertCount(3, $left);
        self::assertSame([], array_intersect($left, [$fails, $succeeds, $failsUnrecoverably, $waits, $succeedsToo]), 'the kept ones have new ids');
        $display = $tester->getDisplay();
        self::assertStringContainsString('Selected: 5 (the oldest messages in failed, at most 200)', $display);
        self::assertStringContainsString('Handled and removed: 2', $display);
        self::assertStringContainsString('Kept in failed after a failure, under a new id: 3', $display);
        self::assertStringContainsString(\sprintf('%s is now %s', $fails, $left[0]), $display);
        self::assertStringContainsString(\sprintf('%s is now %s', $failsUnrecoverably, $left[1]), $display);
        self::assertStringContainsString(\sprintf('%s is now %s (retried by Messenger after a delay)', $waits, $left[2]), $display);
        self::assertStringContainsString('Left in failed: 3', $display);
        self::assertStringContainsString('More messages are left in failed', $display);
    }

    public function testAMaxBelowTheQueueTakesTheOldestAndSaysThatMoreAreLeft(): void
    {
        $first = $this->putInFailed('first');
        $second = $this->putInFailed('second');
        $third = $this->putInFailed('third');
        // The third is the oldest one.
        $this->connection()->executeStatement("UPDATE messenger_messages SET available_at = available_at - interval '1 hour' WHERE id = ?", [$third]);

        $tester = $this->retryAll(['--max' => '2']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(1, ScriptedOutcomeHandler::attempts('third'));
        self::assertSame(1, ScriptedOutcomeHandler::attempts('first'));
        self::assertSame(0, ScriptedOutcomeHandler::attempts('second'));
        self::assertSame([$second], $this->failedIds());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Selected: 2 (the oldest messages in failed, at most 2)', $display);
        self::assertStringContainsString('Handled and removed: 2', $display);
        self::assertStringContainsString('Kept in failed after a failure, under a new id: 0', $display);
        self::assertStringContainsString('Left in failed: 1', $display);
        self::assertStringContainsString('More messages are left in failed', $display);
        self::assertNotSame($first, $second);
    }

    public function testAnEmptyFailedIsADoneRunWithNothingLeft(): void
    {
        $tester = $this->retryAll([]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('Selected: 0', $tester->getDisplay());
        self::assertStringContainsString('Left in failed: 0', $tester->getDisplay());
        self::assertStringNotContainsString('More messages are left', $tester->getDisplay());
    }

    public function testADelayedMessageIsNotSelectedButCountsAsLeft(): void
    {
        $delayed = $this->putInFailed('delayed');
        $this->connection()->executeStatement("UPDATE messenger_messages SET available_at = available_at + interval '1 hour' WHERE id = ?", [$delayed]);

        $tester = $this->retryAll([]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(0, ScriptedOutcomeHandler::attempts('delayed'));
        self::assertStringContainsString('Selected: 0', $tester->getDisplay());
        self::assertStringContainsString('Left in failed: 1', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMaxima(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'not a number' => ['many'];
        yield 'a fraction' => ['1.5'];
        yield 'above the limit' => ['1001'];
        yield 'beyond an integer' => ['99999999999999999999'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMaxima')]
    public function testAnInvalidMaxRetriesNothing(string $max): void
    {
        $this->putInFailed('kept');

        $tester = $this->retryAll(['--max' => $max]);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('--max', $tester->getDisplay());
        self::assertSame(0, ScriptedOutcomeHandler::attempts('kept'));
        self::assertSame(1, $this->rows('failed'));
    }

    public function testAnUnreadableQueueIsExitCodeTwoWithTheReason(): void
    {
        $unreachable = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 1, 'dbname' => 'shop', 'user' => 'x', 'password' => 'x', 'connect_timeout' => 2]);
        $retry = self::getContainer()->get('console.command.messenger_failed_messages_retry');
        self::assertInstanceOf(Command::class, $retry);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new FailedRetryCommand(new FailedMessageQueue($unreachable, new Clock()), $retry, $dispatcher));
        $tester = new ApplicationTester($application);

        $tester->run(['command' => 'app:messaging:failed-retry'], ['interactive' => false]);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('The messages of failed could not be read', $tester->getDisplay());
    }

    private function putInFailed(string $key): string
    {
        $sent = $this->transport('failed')->send(new Envelope(new ScriptedOutcome($key), [
            new BusNameStamp('command.bus'),
            new SentToFailureTransportStamp('scheduled'),
            new RedeliveryStamp(0),
        ]));
        $id = $sent->last(TransportMessageIdStamp::class)?->getId();
        self::assertNotNull($id);

        return (string) $id;
    }

    /**
     * @param array<string, string> $options
     */
    private function retryAll(array $options): ApplicationTester
    {
        self::assertNotNull(self::$kernel);
        $application = new FrameworkApplication(self::$kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run(['command' => 'app:messaging:failed-retry'] + $options, ['interactive' => false]);

        return $tester;
    }

    /**
     * @return list<string>
     */
    private function failedIds(): array
    {
        return array_map(
            'strval',
            $this->connection()->fetchFirstColumn("SELECT id FROM messenger_messages WHERE queue_name = 'failed' ORDER BY id"),
        );
    }
}
