<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * A message received from `failed` (messenger:failed:retry, app:messaging:failed-retry) whose handling fails again
 * stays in `failed` (spec: docs/features/async-reliability/spec.md, D6, W12). Messenger would drop it: its listener of
 * the failure transport never sends there a message received from that transport, and the worker rejects (deletes) the
 * message after the failed event.
 *
 * - An error Messenger does not retry (`failed` has max_retries: 0; an unrecoverable error; a HandlerFailedException of
 *   unrecoverable errors only): the envelope, with the error details stamp of this attempt and its original
 *   SentToFailureTransportStamp, is written again to `failed`, available at once, under a new id. The ids are logged
 *   and the new one is put on the event ({@see KeptInFailureTransportStamp}).
 * - An error Messenger retries anyway (RecoverableExceptionInterface, whatever the retry strategy says): its retry
 *   listener has already sent the envelope back to `failed` with a delay, so nothing is written (one copy, not two).
 *
 * The listener runs after that retry listener (priority 100), which sets willRetry(), and inside the dispatch of the
 * failed event, before the worker rejects the original: a failed write of the copy ends the retry with that error
 * and leaves the original in `failed`. A process killed between the write and the reject leaves both (a duplicate;
 * the handling is idempotent). The failure transport listener of Messenger (-100) skips these messages.
 */
final readonly class KeepFailedMessageListener implements EventSubscriberInterface
{
    /** The failure transport of messenger.yaml (`failure_transport`). */
    public const string FAILURE_TRANSPORT = 'failed';

    public function __construct(
        #[Autowire(service: 'messenger.transport.failed')] private SenderInterface $failureTransport,
        #[Autowire(service: 'monolog.logger.messenger')] private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After SendFailedMessageForRetryListener (100), so willRetry() is known; before
            // SendFailedMessageToFailureTransportListener (-100), which ignores messages of the failure transport.
            WorkerMessageFailedEvent::class => ['onMessageFailed', -50],
        ];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (self::FAILURE_TRANSPORT !== $event->getReceiverName() || $event->willRetry()) {
            return;
        }

        $envelope = $event->getEnvelope();
        $newId = self::idOf($this->failureTransport->send($envelope->with(new DelayStamp(0)))) ?? '';

        $this->logger->warning('Message {class} failed again in the failure transport; kept there as message {new_message_id} (was {message_id}).', [
            'class' => $envelope->getMessage()::class,
            'message_id' => self::idOf($envelope),
            'new_message_id' => $newId,
        ]);

        $event->addStamps(new KeptInFailureTransportStamp($newId));
    }

    /**
     * The id the transport gave the envelope (the Doctrine transport: the number of its row), null without one.
     */
    public static function idOf(Envelope $envelope): ?string
    {
        $id = $envelope->last(TransportMessageIdStamp::class)?->getId();

        return \is_int($id) || \is_string($id) ? (string) $id : null;
    }
}
