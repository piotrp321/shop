<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * The callbacks of the Sentry SDK (config/packages/sentry.yaml: before_send, before_send_transaction,
 * before_breadcrumb). An event or a transaction gets the tag `correlation_id` of the work the process is doing when it
 * is sent: the request, the command or the message of the worker (spec: docs/features/sentry/spec.md, D6). It is read
 * from CorrelationId at that moment, never kept on the scope, so a message of the worker never sends the identifier of
 * the one before. Then everything goes through SentryEventFilter (D4).
 */
final readonly class SentryEventProcessor
{
    public const string TAG = 'correlation_id';

    public function __construct(private SentryEventFilter $filter, private CorrelationId $correlationId)
    {
    }

    public function beforeSend(Event $event, ?EventHint $hint): ?Event
    {
        return $this->filter->filterEvent($this->tagged($event));
    }

    public function beforeSendTransaction(Event $transaction, ?EventHint $hint): ?Event
    {
        return $this->filter->filterEvent($this->tagged($transaction));
    }

    public function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        return $this->filter->filterBreadcrumb($breadcrumb);
    }

    private function tagged(Event $event): Event
    {
        $id = $this->correlationId->current();

        return null === $id ? $event->removeTag(self::TAG) : $event->setTag(self::TAG, $id);
    }
}
