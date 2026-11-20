<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Messaging;

use App\Shared\Infrastructure\Messaging\KeepFailedMessageListener;
use App\Shared\UI\Console\DisableBulkFailedRetryListener;
use App\Tests\Support\Messaging\ScriptedOutcome;
use App\Tests\Support\Messaging\ScriptedOutcomeHandler;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;

/**
 * `messenger:consume` that would take messages from `failed` is disabled in the console (spec:
 * docs/features/async-reliability/spec.md, D6, W12, criterion 14): a message that fails again stays in `failed`
 * available at once, so the worker would handle it again and again until its limits. The workers of compose and the
 * chart name their receivers without `failed` and run as before.
 */
final class FailedConsumeBlockTest extends MessagingKernelTestCase
{
    /**
     * The commands of the two workers in compose (infra/docker-compose.yml) and the chart (the worker and
     * worker-commands Deployments), argument for argument.
     */
    private const array WORKER_COMMANDS = [
        'worker' => ['outbox', 'events', '--time-limit=86400', '--memory-limit=192M'],
        'worker-commands' => ['payment_simulator', 'scheduled', 'promotions', 'jobs', '--time-limit=86400', '--memory-limit=192M'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        ScriptedOutcomeHandler::reset();
        $this->connection()->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'failed'");
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function consumptionsOfFailed(): iterable
    {
        yield 'failed named' => [['command' => 'messenger:consume', 'receivers' => ['failed']], false];
        yield 'failed among others' => [['command' => 'messenger:consume', 'receivers' => ['outbox', 'failed']], false];
        yield 'an abbreviated name' => [['command' => 'messenger:cons', 'receivers' => ['failed']], false];
        // Messenger reads every receiver as a pattern ({^receiver$}) over the names of the transports.
        yield 'a pattern' => [['command' => 'messenger:consume', 'receivers' => ['fail.*']], false];
        yield 'another pattern' => [['command' => 'messenger:consume', 'receivers' => ['f[a]iled']], false];
        yield 'a pattern ignoring the case' => [['command' => 'messenger:consume', 'receivers' => ['(?i)FAILED']], false];
        // The receiver locator also knows the transports by their service ids.
        yield 'the service id' => [['command' => 'messenger:consume', 'receivers' => ['messenger.transport.failed']], false];
        yield 'all' => [['command' => 'messenger:consume', '--all' => true], false];
        yield 'all, failed not excluded' => [['command' => 'messenger:consume', '--all' => true, '--exclude-receivers' => ['outbox']], false];
        // --all ignores the receivers named next to it.
        yield 'all with receivers' => [['command' => 'messenger:consume', 'receivers' => ['outbox'], '--all' => true], false];
        // Messenger excludes by the exact name only.
        yield 'all, the service id excluded' => [['command' => 'messenger:consume', '--all' => true, '--exclude-receivers' => ['messenger.transport.failed']], false];
        yield 'all, a pattern excluded' => [['command' => 'messenger:consume', '--all' => true, '--exclude-receivers' => ['fail.*']], false];
        // The receivers are chosen after the event of the command, in a question: they cannot be checked.
        yield 'no receivers' => [['command' => 'messenger:consume', '--limit' => '1'], false];
        yield 'no receivers, interactive' => [['command' => 'messenger:consume', '--limit' => '1'], true];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('consumptionsOfFailed')]
    public function testAConsumptionOfFailedIsDisabledAndProcessesNothing(array $input, bool $interactive): void
    {
        ScriptedOutcomeHandler::script('kept', ScriptedOutcomeHandler::ERROR);
        $this->putInFailed('kept');

        $tester = $this->console($input + ['--time-limit' => '2'], $interactive);

        self::assertSame(113, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('app:messaging:failed-retry', $tester->getDisplay());
        self::assertStringContainsString('messenger:failed:retry <id>', $tester->getDisplay());
        self::assertSame(0, ScriptedOutcomeHandler::attempts('kept'));
        self::assertSame(1, $this->rows('failed'));
    }

    public function testAllWithFailedExcludedRuns(): void
    {
        $this->putInFailed('kept');

        $tester = $this->console(['command' => 'messenger:consume', '--all' => true, '--exclude-receivers' => ['failed'], '--time-limit' => '1', '--sleep' => '0.05']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(0, ScriptedOutcomeHandler::attempts('kept'));
        self::assertSame(1, $this->rows('failed'));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function workerCommands(): iterable
    {
        foreach (self::WORKER_COMMANDS as $worker => $arguments) {
            yield $worker => [$arguments];
        }
    }

    /**
     * @param list<string> $arguments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('workerCommands')]
    public function testTheCommandOfAWorkerIsNotDisabled(array $arguments): void
    {
        self::assertNotNull(self::$kernel);
        $command = (new Application(self::$kernel))->find('messenger:consume');
        $command->mergeApplicationDefinition();
        $input = new ArgvInput(['bin/console', 'messenger:consume', ...$arguments]);
        $input->bind($command->getDefinition());
        $event = new ConsoleCommandEvent($command, $input, new BufferedOutput());

        (new DisableBulkFailedRetryListener())($event);

        self::assertTrue($event->commandShouldRun());
    }

    /**
     * @param list<string> $arguments
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('workerCommands')]
    public function testTheReceiversOfAWorkerAreConsumedFromTheConsole(array $arguments): void
    {
        $this->putInFailed('kept');
        $receivers = array_values(array_filter($arguments, static fn (string $argument): bool => !str_starts_with($argument, '--')));

        $tester = $this->console(['command' => 'messenger:consume', 'receivers' => $receivers, '--time-limit' => '1', '--sleep' => '0.05']);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(1, $this->rows('failed'));
    }

    private function putInFailed(string $key): void
    {
        $sent = $this->transport('failed')->send(new Envelope(new ScriptedOutcome($key), [
            new BusNameStamp('command.bus'),
            new SentToFailureTransportStamp('scheduled'),
            new RedeliveryStamp(0),
        ]));
        self::assertNotNull(KeepFailedMessageListener::idOf($sent));
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
}
