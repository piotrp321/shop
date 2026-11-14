<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Functional\Shared\Sentry\RecordingOutgoingHttpClient;
use App\Tests\Functional\Shared\Sentry\SentryEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\SentrySdk;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The calls of the API to other services (Keycloak, Varnish, the shop, the payments) with and without Sentry (spec:
 * docs/features/sentry/spec.md, D1 and D7): they carry no sentry-trace and no baggage, because only the panel sends a
 * trace to the API and the API does not propagate it, so without a DSN nothing changes in them. The spans of the HTTP
 * client stay.
 */
final class SentryOutgoingHttpTest extends KernelTestCase
{
    use SentryEnvironment;

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function environments(): iterable
    {
        yield 'without a DSN' => [[]];
        yield 'with a DSN' => [['SENTRY_DSN' => self::SENTRY_DSN]];
        yield 'with a DSN and every request traced' => [['SENTRY_DSN' => self::SENTRY_DSN, 'SENTRY_TRACES_SAMPLE_RATE' => '1']];
    }

    /**
     * @param array<string, string> $variables
     */
    #[DataProvider('environments')]
    public function testACallToAnotherServiceCarriesNoTraceHeaders(array $variables): void
    {
        $this->useSentry($variables);
        $transaction = isset($variables['SENTRY_TRACES_SAMPLE_RATE']) ? $this->startTransaction() : null;
        $recorder = $this->outgoing();

        $this->httpClient()->request('GET', 'http://keycloak:8080/realms/shop/.well-known/openid-configuration');

        self::assertCount(1, $recorder->requests());
        self::assertNotContains('sentry-trace', $recorder->requests()[0]);
        self::assertNotContains('baggage', $recorder->requests()[0]);
        if (null !== $transaction) {
            $spans = array_map(static fn ($span): ?string => $span->getOp(), $transaction->getSpanRecorder()?->getSpans() ?? []);
            self::assertContains('http.client', $spans, 'the span of the call stays');
        }
    }

    /**
     * @param array<string, string> $variables
     */
    private function useSentry(array $variables): void
    {
        if ([] === $variables) {
            self::resetSdk();
        } else {
            $this->enableSentry($variables);
        }
        self::bootKernel();
        $this->outgoing()->offline();
    }

    private function startTransaction(): Transaction
    {
        $hub = SentrySdk::getCurrentHub();
        $transaction = $hub->startTransaction(TransactionContext::make()->setName('test')->setOp('test'));
        $hub->setSpan($transaction);
        self::assertTrue($transaction->getSampled(), 'the transaction is traced');

        return $transaction;
    }

    private function outgoing(): RecordingOutgoingHttpClient
    {
        $recorder = self::getContainer()->get(RecordingOutgoingHttpClient::class);
        \assert($recorder instanceof RecordingOutgoingHttpClient);

        return $recorder;
    }

    private function httpClient(): HttpClientInterface
    {
        $client = self::getContainer()->get('http_client');
        \assert($client instanceof HttpClientInterface);

        return $client;
    }
}
