<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Shared\Infrastructure\Messaging\KeepFailedMessageListener;
use App\Tests\Support\Messaging\ScriptedOutcome;
use App\Tests\Support\Messaging\ScriptedOutcomeHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * A message retried from `failed` that fails again stays in `failed` (spec: docs/features/async-reliability/spec.md, D6,
 * W12, criterion 14), on the real Doctrine transport of the `test_messaging` kernel, retried the way the operator does:
 * `messenger:failed:retry <id> --force` from the console.
 */
final class FailedMessageRetentionTest extends MessagingKernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ScriptedOutcomeHandler::reset();
        $this->connection()->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'failed'");
    }

    public function testEveryFailedRetryKeepsTheMessageAsOneRowWithTheSameContentAndANewId(): void
    {
        $logs = $this->messengerLogs();
        ScriptedOutcomeHandler::script('a', ScriptedOutcomeHandler::ERROR);
        $id = $this->putInFailed('a');
        $ids = [$id];

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            self::assertSame(0, $this->retry($id)->getStatusCode());

            self::assertSame($attempt, ScriptedOutcomeHandler::attempts('a'));
            self::assertSame(1, $this->rows('failed'), 'exactly one row after attempt '.$attempt);
            $newId = $this->onlyFailedId();
            self::assertNotContains($newId, $ids, 'a new id after attempt '.$attempt);
            $kept = $this->find($newId);
            self::assertEquals(new ScriptedOutcome('a'), $kept->getMessage(), 'the same content');
            self::assertSame('scheduled', $kept->last(SentToFailureTransportStamp::class)?->getOriginalReceiverName());
            self::assertSame(
                \sprintf('Scripted error of "a", attempt %d.', $attempt),
                $kept->last(ErrorDetailsStamp::class)?->getExceptionMessage(),
                'the error of this attempt',
            );
            self::assertTrue($this->loggedKept($logs, $id, $newId), 'the old and the new id are logged');
            $id = $newId;
            $ids[] = $newId;
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function errorsMessengerDoesNotRetry(): iterable
    {
        yield 'a plain error (max_retries: 0 of failed)' => [ScriptedOutcomeHandler::ERROR];
        // The handler middleware wraps it: the worker gets a HandlerFailedException with only unrecoverable errors.
        yield 'an unrecoverable error' => [ScriptedOutcomeHandler::UNRECOVERABLE];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('errorsMessengerDoesNotRetry')]
    public function testAnErrorThatMessengerDoesNotRetryKeepsTheMessageAvailableAtOnce(string $error): void
    {
        ScriptedOutcomeHandler::script('b', $error);
        $id = $this->putInFailed('b');

        self::assertSame(0, $this->retry($id)->getStatusCode());

        self::assertSame(1, $this->rows('failed'));
        $newId = $this->onlyFailedId();
        self::assertNotSame($id, $newId);
        self::assertTrue($this->isAvailable($newId), 'available at once');
    }

    public function testARecoverableErrorIsRetriedByMessengerAsOneDelayedRowWithoutACopy(): void
    {
        $logs = $this->messengerLogs();
        ScriptedOutcomeHandler::script('c', ScriptedOutcomeHandler::RECOVERABLE);
        $id = $this->putInFailed('c');

        self::assertSame(0, $this->retry($id)->getStatusCode());

        self::assertSame(1, $this->rows('failed'), 'one row: the retry of Messenger, no copy of the listener');
        $newId = $this->onlyFailedId();
        self::assertNotSame($id, $newId);
        self::assertTrue($this->isDelayed($newId), 'delayed by Messenger');
        self::assertSame(1, RedeliveryStamp::getRetryCountFromEnvelope($this->find($newId)), 'the retry count of Messenger');
        self::assertFalse($this->loggedKept($logs, $id, $newId));
    }

    public function testASuccessfulRetryRemovesTheMessage(): void
    {
        $id = $this->putInFailed('d');

        self::assertSame(0, $this->retry($id)->getStatusCode());

        self::assertSame(1, ScriptedOutcomeHandler::attempts('d'));
        self::assertSame(0, $this->rows('failed'));
    }

    public function testFailedRemoveRemovesAKeptMessage(): void
    {
        ScriptedOutcomeHandler::script('e', ScriptedOutcomeHandler::UNRECOVERABLE);
        $this->retry($this->putInFailed('e'));
        $kept = $this->onlyFailedId();

        $tester = $this->console(['command' => 'messenger:failed:remove', 'id' => [$kept], '--force' => true]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(0, $this->rows('failed'));
    }

    public function testAFailedWriteOfTheCopyEndsTheRetryWithAnErrorAndKeepsTheOriginal(): void
    {
        self::getContainer()->set(KeepFailedMessageListener::class, new KeepFailedMessageListener(
            new class implements SenderInterface {
                public function send(Envelope $envelope): Envelope
                {
                    throw new TransportException('The database refused the copy.');
                }
            },
            new NullLogger(),
        ));
        ScriptedOutcomeHandler::script('f', ScriptedOutcomeHandler::ERROR);
        $id = $this->putInFailed('f');

        $tester = $this->retry($id);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('The database refused the copy.', $tester->getDisplay());
        self::assertSame(1, ScriptedOutcomeHandler::attempts('f'));
        self::assertSame([$id], $this->failedIds(), 'the original is still there');
    }

    public function testTheBulkRetryWithoutAnIdIsDisabledAndProcessesNothing(): void
    {
        ScriptedOutcomeHandler::script('g', ScriptedOutcomeHandler::ERROR);
        $id = $this->putInFailed('g');

        $tester = $this->console(['command' => 'messenger:failed:retry', '--force' => true]);

        self::assertSame(113, $tester->getStatusCode());
        self::assertStringContainsString('app:messaging:failed-retry', $tester->getDisplay());
        self::assertStringContainsString('messenger:failed:retry <id>', $tester->getDisplay());
        self::assertSame(0, ScriptedOutcomeHandler::attempts('g'));
        self::assertSame([$id], $this->failedIds());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function bulkRetriesUnderAnotherSpelling(): iterable
    {
        yield 'an abbreviated name' => [['command' => 'messenger:failed:ret', '--force' => true], false];
        yield 'the transport named' => [['command' => 'messenger:failed:retry', '--transport' => 'failed', '--force' => true], false];
        yield 'without --force' => [['command' => 'messenger:failed:retry'], false];
        // The way an operator runs it in a terminal: Messenger would take message after message until none is left.
        yield 'interactive' => [['command' => 'messenger:failed:retry', '--force' => true], true];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bulkRetriesUnderAnotherSpelling')]
    public function testTheBulkRetryIsDisabledUnderAnotherSpelling(array $input, bool $interactive): void
    {
        ScriptedOutcomeHandler::script('h', ScriptedOutcomeHandler::ERROR);
        $id = $this->putInFailed('h');

        self::assertSame(113, $this->console($input, $interactive)->getStatusCode());
        self::assertSame(0, ScriptedOutcomeHandler::attempts('h'));
        self::assertSame([$id], $this->failedIds());
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

    private function retry(string $id): ApplicationTester
    {
        return $this->console(['command' => 'messenger:failed:retry', 'id' => [$id], '--force' => true]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input, bool $interactive = false): ApplicationTester
    {
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);
        $tester->run($input, ['interactive' => $interactive]);

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

    private function onlyFailedId(): string
    {
        $ids = $this->failedIds();
        self::assertCount(1, $ids);

        return $ids[0];
    }

    private function find(string $id): Envelope
    {
        $failed = $this->transport('failed');
        self::assertInstanceOf(ListableReceiverInterface::class, $failed);
        $envelope = $failed->find($id);
        self::assertNotNull($envelope);

        return $envelope;
    }

    private function isAvailable(string $id): bool
    {
        return (bool) $this->connection()->fetchOne(
            "SELECT available_at <= (clock_timestamp() AT TIME ZONE 'UTC') FROM messenger_messages WHERE id = ?",
            [$id],
        );
    }

    private function isDelayed(string $id): bool
    {
        return (bool) $this->connection()->fetchOne(
            "SELECT available_at > (clock_timestamp() AT TIME ZONE 'UTC') + interval '30 seconds' FROM messenger_messages WHERE id = ?",
            [$id],
        );
    }

    private function messengerLogs(): TestHandler
    {
        $logger = self::getContainer()->get('monolog.logger.messenger');
        self::assertInstanceOf(Logger::class, $logger);
        $logs = new TestHandler();
        $logger->pushHandler($logs);

        return $logs;
    }

    private function loggedKept(TestHandler $logs, string $oldId, string $newId): bool
    {
        foreach ($logs->getRecords() as $record) {
            if ($oldId === (string) ($record->context['message_id'] ?? '') && $newId === (string) ($record->context['new_message_id'] ?? '')) {
                return true;
            }
        }

        return false;
    }
}
