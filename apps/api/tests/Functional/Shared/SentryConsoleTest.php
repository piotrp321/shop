<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Shared\Infrastructure\Observability\CorrelationId;
use App\Tests\Functional\Shared\Sentry\SentryEnvironment;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * An uncaught exception of a console command is one event (spec: docs/features/sentry/spec.md, D5: the console
 * listener of the bundle), with the correlation identifier of the run.
 */
final class SentryConsoleTest extends KernelTestCase
{
    use SentryEnvironment;

    public function testAnUncaughtExceptionOfACommandIsOneEvent(): void
    {
        $this->enableSentry();
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);
        $application->addCommand((new Command('test:sentry-failure'))->setCode(static function (): int {
            throw new \RuntimeException('Failing row contains (jan.kowalski@example.pl).');
        }));

        $exitCode = $application->run(new ArrayInput(['command' => 'test:sentry-failure']), new BufferedOutput());

        self::assertSame(1, $exitCode);
        $events = self::sentry(self::getContainer())->items('event');
        self::assertCount(1, $events);
        $correlationId = self::getContainer()->get(CorrelationId::class);
        \assert($correlationId instanceof CorrelationId);
        self::assertNotNull($correlationId->current());
        self::assertIsArray($events[0]['tags'] ?? null);
        self::assertSame($correlationId->current(), $events[0]['tags']['correlation_id'] ?? null);
        self::assertSame('test:sentry-failure', $events[0]['tags']['console.command'] ?? null);
        self::assertIsArray($events[0]['exception'] ?? null);
        self::assertIsArray($events[0]['exception']['values'] ?? null);
        self::assertIsArray($events[0]['exception']['values'][0] ?? null);
        self::assertSame('Failing row contains ([REDACTED]).', $events[0]['exception']['values'][0]['value'] ?? null);
    }
}
