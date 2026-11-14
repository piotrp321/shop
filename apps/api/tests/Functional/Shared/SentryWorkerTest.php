<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Shared\Infrastructure\Observability\CorrelationIdStamp;
use App\Tests\Functional\Shared\Sentry\SentryEnvironment;
use App\Tests\Support\TestMessage;
use Sentry\SentrySdk;
use Sentry\State\Scope;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Messenger\Worker;

/**
 * The worker (spec: docs/features/sentry/spec.md, D5 and D6): a message that fails its last try is one event with the
 * correlation identifier of the message; a failure that will be retried is none; the scope of one message does not
 * reach the next one.
 */
final class SentryWorkerTest extends KernelTestCase
{
    use SentryEnvironment;

    /** The tries of `scheduled` (config/packages/messenger.yaml): the next failure of a message redelivered 5 times is its last. */
    private const int MAX_RETRIES = 5;

    public function testEachMessageThatFailsItsLastTryIsOneEventWithItsOwnCorrelationIdentifierAndScope(): void
    {
        $this->enableSentry();
        self::bootKernel();
        $transport = self::getContainer()->get('messenger.transport.scheduled');
        \assert($transport instanceof TransportInterface);
        $transport->send(new Envelope(new TestMessage('runtime'), [new CorrelationIdStamp('0199a000-0000-7000-8000-00000000000a'), new RedeliveryStamp(self::MAX_RETRIES)]));
        $transport->send(new Envelope(new TestMessage('runtime'), [new CorrelationIdStamp('0199a000-0000-7000-8000-00000000000b'), new RedeliveryStamp(self::MAX_RETRIES)]));
        $transport->send(new Envelope(new TestMessage('runtime'), [new CorrelationIdStamp('0199a000-0000-7000-8000-00000000000c')]));

        $dispatcher = self::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        // Something of the handling of the first message changes the scope of Sentry.
        $messages = 0;
        $dispatcher->addListener(WorkerMessageReceivedEvent::class, static function () use (&$messages): void {
            if (1 === ++$messages) {
                SentrySdk::getCurrentHub()->configureScope(static fn (Scope $scope) => $scope->setTag('left_over', 'by the first message'));
            }
        });
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(3));
        $bus = self::getContainer()->get('command.bus');
        \assert($bus instanceof MessageBusInterface);

        (new Worker(['scheduled' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);

        $events = self::sentry(self::getContainer())->items('event');
        self::assertCount(2, $events, 'the two last tries, not the one that will be retried');
        $tags = array_map(static fn (array $event): mixed => $event['tags'] ?? null, $events);
        self::assertIsArray($tags[0]);
        self::assertIsArray($tags[1]);
        self::assertSame('0199a000-0000-7000-8000-00000000000a', $tags[0]['correlation_id'] ?? null);
        self::assertSame('0199a000-0000-7000-8000-00000000000b', $tags[1]['correlation_id'] ?? null);
        self::assertArrayNotHasKey('left_over', $tags[1], 'the scope of the first message reached the second');
        self::assertSame(self::SENTRY_RELEASE, $events[1]['release'] ?? null);
    }
}
