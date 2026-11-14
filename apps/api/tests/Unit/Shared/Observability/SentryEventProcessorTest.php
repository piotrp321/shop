<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Observability;

use App\Shared\Infrastructure\Observability\CorrelationId;
use App\Shared\Infrastructure\Observability\SentryEventFilter;
use App\Shared\Infrastructure\Observability\SentryEventProcessor;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\UserDataBag;

/**
 * The callbacks of the SDK (before_send, before_send_transaction, before_breadcrumb): every event and transaction has
 * the tag correlation_id of the work the process is doing when it is sent (spec: docs/features/sentry/spec.md, D6), and
 * everything goes through the filter (D4).
 */
final class SentryEventProcessorTest extends TestCase
{
    private CorrelationId $correlationId;
    private SentryEventProcessor $processor;

    protected function setUp(): void
    {
        $this->correlationId = new CorrelationId();
        $this->processor = new SentryEventProcessor(new SentryEventFilter(), $this->correlationId);
    }

    public function testAnEventGetsTheCurrentCorrelationIdentifierAndIsFiltered(): void
    {
        $id = $this->correlationId->start();
        $event = Event::createEvent();
        $event->setUser(UserDataBag::createFromUserIpAddress('203.0.113.45'));

        $sent = $this->processor->beforeSend($event, null);

        self::assertSame($id, $sent?->getTags()['correlation_id'] ?? null);
        self::assertNull($sent?->getUser());
    }

    public function testATransactionGetsTheCurrentCorrelationIdentifierAndIsFiltered(): void
    {
        $id = $this->correlationId->start();
        $transaction = Event::createTransaction();
        $transaction->setTransaction('GET http://localhost/auth/callback?code=v-oidc-code');

        $sent = $this->processor->beforeSendTransaction($transaction, null);

        self::assertSame($id, $sent?->getTags()['correlation_id'] ?? null);
        self::assertSame('GET http://localhost/auth/callback', $sent?->getTransaction());
    }

    public function testATagLeftByEarlierWorkIsReplacedByTheCurrentIdentifier(): void
    {
        $event = Event::createEvent();
        $event->setTag('correlation_id', 'an-earlier-message');
        $id = $this->correlationId->start();

        self::assertSame($id, $this->processor->beforeSend($event, null)?->getTags()['correlation_id'] ?? null);
    }

    public function testWithoutAnIdentifierTheEventHasNoCorrelationTag(): void
    {
        $event = Event::createEvent();
        $event->setTag('correlation_id', 'an-earlier-message');

        self::assertArrayNotHasKey('correlation_id', $this->processor->beforeSend($event, null)?->getTags() ?? []);
    }

    public function testABreadcrumbIsFiltered(): void
    {
        $breadcrumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'app', 'Sent to jan.kowalski@example.pl');

        self::assertSame('Sent to [REDACTED]', $this->processor->beforeBreadcrumb($breadcrumb)?->getMessage());
    }
}
