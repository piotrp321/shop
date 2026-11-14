<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Shared\Infrastructure\Observability\CorrelationId;
use App\Tests\Functional\Shared\Sentry\SentryEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The explicit logs of the application (spec: docs/features/sentry/spec.md, P6 and D5): a log from `error` up is one
 * event with no context but the correlation identifier; a lower level, the channels of the framework (which repeat an
 * exception reported elsewhere or are no error of the application) and a log with an exception are none; nothing of
 * the logs becomes a breadcrumb.
 */
final class SentryLogTest extends KernelTestCase
{
    use SentryEnvironment;

    private const string EMAIL = 'jan.kowalski@example.pl';

    public function testAnErrorOfTheApplicationIsOneEventWithNoContextButTheCorrelationIdentifier(): void
    {
        $this->enableSentry();
        self::bootKernel();
        $id = self::correlationId()->start();

        self::logger()->info('Order placed by '.self::EMAIL, ['email' => self::EMAIL]);
        self::logger()->warning('Slow payment of '.self::EMAIL, ['email' => self::EMAIL]);
        self::logger()->error('Payment {paymentId} requires a manual refund', ['paymentId' => 'payment-1', 'email' => self::EMAIL, 'orderId' => 'order-1']);

        $events = self::sentry(self::getContainer())->items('event');
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertSame('error', $event['level'] ?? null);
        self::assertIsArray($event['tags'] ?? null);
        self::assertSame($id, $event['tags']['correlation_id'] ?? null);
        self::assertSame(self::SENTRY_RELEASE, $event['release'] ?? null);
        self::assertArrayNotHasKey('extra', $event, 'no context of the log');
        self::assertArrayNotHasKey('breadcrumbs', $event, 'no breadcrumbs of the logs');
        $sent = implode("\n", self::sentry(self::getContainer())->envelopes());
        self::assertStringContainsString('requires a manual refund', $sent);
        foreach ([self::EMAIL, 'payment-1', 'order-1', 'Slow payment', 'Order placed'] as $value) {
            self::assertStringNotContainsString($value, $sent);
        }
    }

    public function testAWarningIsNoEvent(): void
    {
        $this->enableSentry();
        self::bootKernel();

        self::logger()->warning('Slow payment');

        self::assertSame([], self::sentry(self::getContainer())->envelopes());
    }

    public function testAnErrorWithAnExceptionIsNoEvent(): void
    {
        $this->enableSentry();
        self::bootKernel();

        self::logger()->error('Delivery failed', ['exception' => new \RuntimeException('Delivery failed')]);

        self::assertSame([], self::sentry(self::getContainer())->envelopes());
    }

    #[DataProvider('channelsOfTheFramework')]
    public function testAnErrorOfAChannelOfTheFrameworkIsNoEvent(string $channel): void
    {
        $this->enableSentry();
        self::bootKernel();
        $logger = self::getContainer()->get('monolog.logger.'.$channel);
        \assert($logger instanceof LoggerInterface);

        $logger->critical('Uncaught PHP Exception RuntimeException: "internal"');

        self::assertSame([], self::sentry(self::getContainer())->envelopes());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function channelsOfTheFramework(): iterable
    {
        foreach (['request', 'console', 'messenger', 'php', 'deprecation', 'cache'] as $channel) {
            yield $channel => [$channel];
        }
    }

    public function testWithoutADsnAnErrorIsNotSent(): void
    {
        self::bootKernel();

        self::logger()->error('Payment requires a manual refund');

        self::assertSame([], self::sentry(self::getContainer())->envelopes());
    }

    private static function logger(): LoggerInterface
    {
        $logger = self::getContainer()->get(LoggerInterface::class);
        \assert($logger instanceof LoggerInterface);

        return $logger;
    }

    private static function correlationId(): CorrelationId
    {
        $correlationId = self::getContainer()->get(CorrelationId::class);
        \assert($correlationId instanceof CorrelationId);

        return $correlationId;
    }
}
