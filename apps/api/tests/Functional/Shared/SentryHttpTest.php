<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use App\Tests\Functional\Shared\Sentry\SentryEnvironment;
use App\Tests\Support\Security\TestTokenFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The API with a DSN and the recording transport (spec: docs/features/sentry/spec.md, D1, D4, D5, D6, D7 and the
 * threat model, paths 1-5): a server error is exactly one event, filtered; a client error is none; without a DSN
 * nothing is sent; requests are traced with the spans of Doctrine, except /health, and continue an incoming trace.
 */
final class SentryHttpTest extends WebTestCase
{
    use SentryEnvironment;

    private const string EMAIL = 'jan.kowalski@example.pl';
    private const string BEARER = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJqYW4ifQ.c2lnbmF0dXJl';
    private const string ORDER_ACCESS_TOKEN = 'v-order-access-token-0123456789';
    private const string DISCOUNT_CODE = 'RABAT-SECRET-CODE';
    private const string ADDRESS = 'ul. Długa 5, 00-001 Warszawa';
    private const string CLIENT_IP = '203.0.113.45';
    // Constants, because the SDK sends the lines of code around each frame: a literal in a test would be sent as code.
    private const string CART_ID = 'a76d8b8d-a070-48da-bb73-bd2674c26f09';
    private const string QUERY = 'q=jan.kowalski%40example.pl&discountCode=RABAT-SECRET-CODE&returnTo=%2Faccount&code=v-oidc-code&state=v-oidc-state';

    public function testAServerErrorIsOneEventWithTheReleaseTheEnvironmentAndTheCorrelationIdOfTheResponse(): void
    {
        $this->enableSentry();
        $client = $this->clientWithController(static function (): never {
            self::placeOrder(self::EMAIL, self::ADDRESS);
        });

        $client->request(
            'POST',
            '/_test/sentry?'.self::QUERY,
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.self::BEARER,
                'HTTP_ORDER_ACCESS_TOKEN' => self::ORDER_ACCESS_TOKEN,
                'HTTP_COOKIE' => 'store_session=v-session-cookie; store_cart=v-cart-cookie',
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (test)',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'REMOTE_ADDR' => self::CLIENT_IP,
            ],
            content: json_encode(['email' => self::EMAIL, 'address' => self::ADDRESS, 'discountCode' => self::DISCOUNT_CODE], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(500);
        $events = self::sentry($client->getContainer())->items('event');
        self::assertCount(1, $events, 'one event for one error');
        $event = $events[0];
        self::assertSame(self::SENTRY_RELEASE, $event['release'] ?? null);
        self::assertSame(self::SENTRY_ENVIRONMENT, $event['environment'] ?? null);
        self::assertIsArray($event['tags'] ?? null);
        self::assertSame($client->getResponse()->headers->get('X-Correlation-ID'), $event['tags']['correlation_id'] ?? null);

        // Paths 1-6 of the threat model: no headers but three, no cookies, body, query, user or address of the client.
        self::assertIsArray($event['request'] ?? null);
        self::assertSame(['method', 'url', 'headers'], array_keys($event['request']));
        self::assertSame('http://localhost/_test/sentry', $event['request']['url']);
        self::assertIsArray($event['request']['headers']);
        self::assertEqualsCanonicalizing(['user-agent', 'content-type', 'accept'], array_map(strtolower(...), array_keys($event['request']['headers'])));
        self::assertArrayNotHasKey('user', $event);
        self::assertArrayNotHasKey('extra', $event);
        self::assertSentNothingOf($client, [self::EMAIL, 'jan.kowalski', self::BEARER, self::ORDER_ACCESS_TOKEN, 'v-session-cookie', 'v-cart-cookie', self::DISCOUNT_CODE, 'Długa', 'v-oidc-code', 'v-oidc-state', self::CLIENT_IP]);

        // The message of the exception is masked, and the frames have no arguments (path 5).
        self::assertIsArray($event['exception'] ?? null);
        self::assertIsArray($event['exception']['values'] ?? null);
        $exception = $event['exception']['values'][0];
        self::assertIsArray($exception);
        self::assertSame('Failing row contains ([REDACTED]).', $exception['value'] ?? null);
        self::assertIsArray($exception['stacktrace'] ?? null);
        self::assertIsArray($exception['stacktrace']['frames'] ?? null);
        foreach ($exception['stacktrace']['frames'] as $frame) {
            self::assertIsArray($frame);
            self::assertArrayNotHasKey('vars', $frame);
        }
    }

    /**
     * @param \Closure(KernelBrowser): mixed $request
     */
    #[DataProvider('clientErrors')]
    public function testAClientErrorIsNoEvent(\Closure $request, int $status): void
    {
        $this->enableSentry();
        $client = self::createClient();
        $client->disableReboot();

        $request($client);

        self::assertResponseStatusCodeSame($status);
        self::assertSame([], self::sentry($client->getContainer())->items('event'));
    }

    /**
     * @return iterable<string, array{\Closure(KernelBrowser): mixed, int}>
     */
    public static function clientErrors(): iterable
    {
        yield '400' => [static fn (KernelBrowser $client) => $client->request('GET', '/_test/invalid'), 400];
        yield '401' => [static fn (KernelBrowser $client) => $client->request('GET', '/api/v1/admin/products'), 401];
        yield '403' => [static fn (KernelBrowser $client) => $client->request('GET', '/api/v1/admin/orders', server: ['HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['marketing'])]), 403];
        yield '404' => [static fn (KernelBrowser $client) => $client->request('GET', '/api/v1/nothing-here'), 404];
        yield '405' => [static fn (KernelBrowser $client) => $client->request('DELETE', '/health'), 405];
        yield '409 Conflict' => [static fn (KernelBrowser $client) => $client->request('GET', '/_test/project-conflict'), 409];
        yield '404 NotFound' => [static fn (KernelBrowser $client) => $client->request('GET', '/_test/project-exception'), 404];
        yield '422 InvalidInput' => [static fn (KernelBrowser $client) => $client->request('GET', '/_test/project-invalid'), 422];
        yield '422 of a request body' => [static fn (KernelBrowser $client) => $client->jsonRequest('POST', '/api/v1/orders', []), 422];
        yield '429' => [static function (KernelBrowser $client): void {
            // The limit of the order placement in `when@test` is 10 (config/packages/rate_limiter.yaml).
            for ($attempt = 0; $attempt <= 10; ++$attempt) {
                $client->jsonRequest('POST', '/api/v1/orders', [], server: ['REMOTE_ADDR' => '198.51.100.78']);
            }
        }, 429];
        yield 'a handler failure of NotFound' => [static fn (KernelBrowser $client) => $client->request('GET', '/_test/handler/project'), 404];
    }

    public function testWithoutADsnNothingIsSent(): void
    {
        $client = $this->clientWithController(static function (): never {
            throw new \RuntimeException('internal');
        });

        $client->request('GET', '/_test/sentry');

        self::assertResponseStatusCodeSame(500);
        self::assertSame([], self::sentry($client->getContainer())->envelopes());
        $hub = $client->getContainer()->get(HubInterface::class);
        \assert($hub instanceof HubInterface);
        self::assertNull($hub->getClient()?->getOptions()->getDsn());
    }

    public function testTheTransportWaitsAtMostTwoSeconds(): void
    {
        $this->enableSentry();
        self::bootKernel();
        $hub = self::getContainer()->get(HubInterface::class);
        \assert($hub instanceof HubInterface);
        $options = $hub->getClient()?->getOptions();

        self::assertSame(2.0, $options?->getHttpConnectTimeout());
        self::assertSame(2.0, $options->getHttpTimeout());
        self::assertFalse($options->shouldSendDefaultPii());
        self::assertSame('none', $options->getMaxRequestBodySize());
    }

    public function testARequestIsATransactionWithTheSpansOfDoctrineWithPlaceholdersOnly(): void
    {
        $this->enableSentry(['SENTRY_TRACES_SAMPLE_RATE' => '1']);
        $client = $this->clientWithController(static function (): Response {
            $connection = self::getContainer()->get(Connection::class);
            \assert($connection instanceof Connection);
            $connection->executeQuery('SELECT count(*) FROM ordering_order WHERE lower(email) = lower(?)', [self::EMAIL])->fetchOne();

            return new Response('ok');
        });

        $client->request('GET', '/_test/sentry?q='.urlencode(self::EMAIL));

        self::assertResponseIsSuccessful();
        $transactions = self::sentry($client->getContainer())->items('transaction');
        self::assertCount(1, $transactions);
        $transaction = $transactions[0];
        self::assertIsArray($transaction['tags'] ?? null);
        self::assertSame($client->getResponse()->headers->get('X-Correlation-ID'), $transaction['tags']['correlation_id'] ?? null);
        self::assertIsArray($transaction['spans'] ?? null);
        $queries = array_values(array_filter(
            $transaction['spans'],
            static fn (mixed $span): bool => \is_array($span) && \is_string($span['op'] ?? null) && str_starts_with($span['op'], 'db') && \is_string($span['description'] ?? null) && str_contains($span['description'], 'ordering_order'),
        ));
        self::assertNotSame([], $queries, 'the query has a span');
        foreach ($queries as $span) {
            self::assertSame('SELECT count(*) FROM ordering_order WHERE lower(email) = lower(?)', $span['description']);
        }
        self::assertSentNothingOf($client, [self::EMAIL, 'jan.kowalski']);
    }

    public function testTheIdentifierOfAGuestCartIsNotSentInATransaction(): void
    {
        $this->enableSentry(['SENTRY_TRACES_SAMPLE_RATE' => '1']);
        $client = self::createClient();
        $client->disableReboot();

        $client->request('GET', '/api/v1/guest-carts/'.self::CART_ID.'/summary?x=1');

        $transactions = self::sentry($client->getContainer())->items('transaction');
        self::assertCount(1, $transactions);
        // Symfony names a transaction after the route, not the path.
        self::assertSame('GET cart_guest_summary', $transactions[0]['transaction'] ?? null);
        self::assertIsArray($transactions[0]['request'] ?? null);
        self::assertIsString($transactions[0]['request']['url'] ?? null);
        self::assertStringContainsString('/guest-carts/', $transactions[0]['request']['url']);
        self::assertSentNothingOf($client, [self::CART_ID]);
    }

    public function testTheIdentifierOfAGuestCartIsNotSentInAnEvent(): void
    {
        $this->enableSentry();
        $client = self::createClient();
        $client->disableReboot();
        $dispatcher = $client->getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(KernelEvents::REQUEST, static function (RequestEvent $event): void {
            if (str_contains($event->getRequest()->getPathInfo(), '/guest-carts/')) {
                $event->getRequest()->attributes->set('_controller', static function (): never {
                    throw new \RuntimeException('internal');
                });
            }
        }, 33);

        $client->request('POST', '/api/v1/guest-carts/'.self::CART_ID.'/items?x=1');

        self::assertResponseStatusCodeSame(500);
        $events = self::sentry($client->getContainer())->items('event');
        self::assertCount(1, $events);
        self::assertIsArray($events[0]['request'] ?? null);
        self::assertIsString($events[0]['request']['url'] ?? null);
        self::assertStringContainsString('/guest-carts/', $events[0]['request']['url']);
        self::assertSentNothingOf($client, [self::CART_ID]);
    }

    public function testHealthIsNotTraced(): void
    {
        $this->enableSentry(['SENTRY_TRACES_SAMPLE_RATE' => '1']);
        $client = self::createClient();
        $client->disableReboot();

        $client->request('GET', '/health');
        self::assertResponseIsSuccessful();
        self::assertSame([], self::sentry($client->getContainer())->items('transaction'));

        // Another request of the same kernel is.
        $client->request('GET', '/api/v1/categories');
        self::assertResponseIsSuccessful();
        self::assertCount(1, self::sentry($client->getContainer())->items('transaction'));
    }

    public function testTheApiContinuesTheTraceOfTheRequest(): void
    {
        // Sampling 0: only the decision of the caller (sampled, the last 1) makes the API trace the request.
        $this->enableSentry(['SENTRY_TRACES_SAMPLE_RATE' => '0']);
        $client = self::createClient();

        $client->request('GET', '/api/v1/categories', server: [
            'HTTP_SENTRY_TRACE' => '771a43a4192642f0b136d5159a501700-9c2a6db8c79068a2-1',
            'HTTP_BAGGAGE' => 'sentry-trace_id=771a43a4192642f0b136d5159a501700,sentry-sampled=true,sentry-sample_rate=1',
        ]);

        self::assertResponseIsSuccessful();
        $transactions = self::sentry($client->getContainer())->items('transaction');
        self::assertCount(1, $transactions);
        self::assertIsArray($transactions[0]['contexts'] ?? null);
        self::assertIsArray($transactions[0]['contexts']['trace'] ?? null);
        self::assertSame('771a43a4192642f0b136d5159a501700', $transactions[0]['contexts']['trace']['trace_id'] ?? null);
        self::assertSame('9c2a6db8c79068a2', $transactions[0]['contexts']['trace']['parent_span_id'] ?? null);
    }

    /**
     * Throws from a function with personal data in its arguments, which PHP keeps in the trace of the exception.
     */
    private static function placeOrder(string $email, string $address): never
    {
        throw new \RuntimeException('Failing row contains ('.$email.', '.$address.').');
    }

    /**
     * A client whose requests to /_test/sentry run the controller: it is set on the request before the router, which
     * then leaves the request alone.
     */
    private function clientWithController(\Closure $controller): KernelBrowser
    {
        $client = self::createClient();
        $client->disableReboot();
        $dispatcher = $client->getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(KernelEvents::REQUEST, static function (RequestEvent $event) use ($controller): void {
            if ('/_test/sentry' === $event->getRequest()->getPathInfo()) {
                $event->getRequest()->attributes->set('_controller', $controller);
            }
        }, 33);

        return $client;
    }

    /**
     * @param list<string> $values
     */
    private static function assertSentNothingOf(KernelBrowser $client, array $values): void
    {
        $sent = implode("\n", self::sentry($client->getContainer())->envelopes());
        self::assertNotSame('', $sent);
        foreach ($values as $value) {
            self::assertStringNotContainsString($value, $sent, $value.' was sent to Sentry');
            // As JSON writes it (escaped slashes and letters outside ASCII).
            self::assertStringNotContainsString(trim(json_encode($value, \JSON_THROW_ON_ERROR), '"'), $sent, $value.' was sent to Sentry');
        }
    }
}
