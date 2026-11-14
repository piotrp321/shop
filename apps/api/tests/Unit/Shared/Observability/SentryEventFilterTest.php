<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Observability;

use App\Shared\Infrastructure\Observability\SentryEventFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\UserDataBag;

/**
 * The filter of the events and transactions sent to Sentry (spec: docs/features/sentry/spec.md, D4 and the threat
 * model, paths 1-6): what leaves the API has no headers but three, no cookies, body, user or address of the client, no
 * query strings, no arguments of the frames, no extra data but the correlation identifier, and the texts are masked
 * with the patterns of the log masking of Alloy (infra/k8s/argocd/apps/monitoring.yaml).
 */
final class SentryEventFilterTest extends TestCase
{
    private const string EMAIL = 'jan.kowalski@example.pl';
    private const string JWT = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTYiLCJlbWFpbCI6ImphbkBleGFtcGxlLnBsIn0.c2lnbmF0dXJlLXNlY3JldA';
    private const string QUERY = 'q=jan.kowalski%40example.pl&discountCode=RABAT-SECRET&returnTo=%2Faccount&code=v-oidc-code&state=v-oidc-state';

    private SentryEventFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new SentryEventFilter();
    }

    public function testTheRequestKeepsOnlyItsMethodItsAddressWithoutTheQueryAndThreeHeaders(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'method' => 'POST',
            'url' => 'http://localhost/api/v1/orders?'.self::QUERY.'#fragment',
            'query_string' => self::QUERY,
            'data' => ['email' => self::EMAIL, 'address' => 'ul. Długa 5'],
            'cookies' => ['store_session' => 'v-session-cookie', 'store_cart' => 'v-cart-cookie'],
            'env' => ['REMOTE_ADDR' => '203.0.113.45'],
            'headers' => [
                'Authorization' => ['Bearer '.self::JWT],
                'cookie' => ['store_session=v-session-cookie; store_cart=v-cart-cookie'],
                'Order-Access-Token' => ['v-order-access-token'],
                'X-Forwarded-For' => ['203.0.113.45'],
                'user-agent' => ['Mozilla/5.0'],
                'Content-Type' => ['application/json'],
                'accept' => 'application/json',
            ],
        ]);

        $this->filter->filterEvent($event);

        self::assertSame(
            [
                'method' => 'POST',
                'url' => 'http://localhost/api/v1/orders',
                'headers' => ['user-agent' => ['Mozilla/5.0'], 'Content-Type' => ['application/json'], 'accept' => 'application/json'],
            ],
            $event->getRequest(),
        );
    }

    public function testAnAddressInTheRequestIsMasked(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['method' => 'GET', 'url' => 'http://localhost/api/v1/customers/'.self::EMAIL]);

        $this->filter->filterEvent($event);

        self::assertSame('http://localhost/api/v1/customers/[REDACTED]', $event->getRequest()['url']);
    }

    public function testTheEventHasNoUser(): void
    {
        $event = Event::createEvent();
        $event->setUser(new UserDataBag('a-staff-id', self::EMAIL, '203.0.113.45', 'jankowalski_login'));

        $this->filter->filterEvent($event);

        self::assertNull($event->getUser());
    }

    public function testTheExtraDataKeepsOnlyTheCorrelationIdentifier(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['correlation_id' => '0199-correlation', 'monolog.channel' => 'app', 'monolog.context' => ['email' => self::EMAIL]]);

        $this->filter->filterEvent($event);

        self::assertSame(['correlation_id' => '0199-correlation'], $event->getExtra());
    }

    public function testTheValuesOfTheContextsButTheTraceAreRemovedAndTheTraceIsFiltered(): void
    {
        $event = Event::createEvent();
        $event->setContext('customer', ['email' => self::EMAIL]);
        $event->setContext('trace', [
            'trace_id' => '771a43a4192642f0b136d5159a501700',
            'span_id' => '9c2a6db8c79068a2',
            'op' => 'http.server',
            'data' => ['http.url' => 'http://localhost/api/v1/orders?'.self::QUERY, 'http.query' => self::QUERY, 'http.request.method' => 'GET'],
        ]);

        $this->filter->filterEvent($event);

        self::assertSame(['email' => '[REDACTED]'], $event->getContexts()['customer']);
        self::assertSame(
            [
                'trace_id' => '771a43a4192642f0b136d5159a501700',
                'span_id' => '9c2a6db8c79068a2',
                'op' => 'http.server',
                'data' => ['http.url' => 'http://localhost/api/v1/orders', 'http.request.method' => 'GET'],
            ],
            $event->getContexts()['trace'],
        );
    }

    public function testTheMessageAndItsParametersAreMasked(): void
    {
        $event = Event::createEvent();
        $event->setMessage('The order of %s needs a refund', [self::EMAIL], 'The order of '.self::EMAIL.' needs a refund');

        $this->filter->filterEvent($event);

        self::assertSame('The order of %s needs a refund', $event->getMessage());
        self::assertSame(['[REDACTED]'], $event->getMessageParams());
        self::assertSame('The order of [REDACTED] needs a refund', $event->getMessageFormatted());
    }

    public function testTheValuesOfTheExceptionsAreMaskedAndTheFramesHaveNoArguments(): void
    {
        $frame = new Frame('placeOrder', 'src/Ordering/PlaceOrder.php', 10, null, null, ['email' => self::EMAIL]);
        $event = Event::createEvent();
        $event->setExceptions([
            new ExceptionDataBag(new \RuntimeException('Failing row contains (1, '.self::EMAIL.', Jan Kowalski).'), new Stacktrace([$frame])),
            new ExceptionDataBag(new \RuntimeException('Token Bearer '.self::JWT.' was refused')),
        ]);
        $event->setStacktrace(new Stacktrace([new Frame('handle', 'src/Kernel.php', 1, null, null, ['password' => 'Hunter2'])]));

        $this->filter->filterEvent($event);

        self::assertSame('Failing row contains ([REDACTED]).', $event->getExceptions()[0]->getValue());
        self::assertSame('Token Bearer [REDACTED] was refused', $event->getExceptions()[1]->getValue());
        self::assertSame([], $event->getExceptions()[0]->getStacktrace()?->getFrames()[0]->getVars());
        self::assertSame([], $event->getStacktrace()?->getFrames()[0]->getVars());
    }

    public function testTheBreadcrumbsOfAnEventAreFiltered(): void
    {
        $event = Event::createEvent();
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', 'GET http://api/api/v1/orders?'.self::QUERY, ['url' => 'http://api/api/v1/orders?'.self::QUERY])]);

        $this->filter->filterEvent($event);

        self::assertSame('GET http://api/api/v1/orders', $event->getBreadcrumbs()[0]->getMessage());
        self::assertSame(['url' => 'http://api/api/v1/orders'], $event->getBreadcrumbs()[0]->getMetadata());
    }

    public function testABreadcrumbLosesTheQueryTheHeadersTheCookiesAndTheBodyAndIsMasked(): void
    {
        $breadcrumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'app', 'Sent to '.self::EMAIL, [
            'url' => 'http://store/checkout?'.self::QUERY,
            'http.query' => self::QUERY,
            'http.fragment' => 'fragment',
            'http.request.header.authorization' => 'Bearer '.self::JWT,
            'http.request.cookies' => 'store_session=v-session-cookie',
            'http.request.body.data' => '{"email":"'.self::EMAIL.'"}',
            'status_code' => 200,
            'note' => 'password=Hunter2',
        ]);

        $filtered = $this->filter->filterBreadcrumb($breadcrumb);

        self::assertSame('Sent to [REDACTED]', $filtered->getMessage());
        self::assertSame(['url' => 'http://store/checkout', 'status_code' => 200, 'note' => 'password=[REDACTED]'], $filtered->getMetadata());
    }

    public function testTheNameOfATransactionHasNoQuery(): void
    {
        $event = Event::createTransaction();
        $event->setTransaction('GET http://localhost/auth/callback?'.self::QUERY);

        $this->filter->filterEvent($event);

        self::assertSame('GET http://localhost/auth/callback', $event->getTransaction());
    }

    public function testTheHttpSpansLoseTheQueryInTheirNameAndTheirData(): void
    {
        $span = new Span(SpanContext::make()->setOp('http.client')->setDescription('GET http://varnish/api/v1/products?'.self::QUERY)->setData([
            'http.url' => 'http://varnish/api/v1/products?'.self::QUERY,
            'url.full' => 'http://varnish/api/v1/products?'.self::QUERY.'#top',
            'http.query' => self::QUERY,
            'http.fragment' => 'top',
            'http.request.method' => 'GET',
            'http.request.header.authorization' => 'Bearer '.self::JWT,
            'http.response.body.data' => '{"email":"'.self::EMAIL.'"}',
        ]));
        $event = Event::createTransaction();
        $event->setSpans([$span]);

        $this->filter->filterEvent($event);

        self::assertSame('GET http://varnish/api/v1/products', $span->getDescription());
        self::assertSame(
            ['http.url' => 'http://varnish/api/v1/products', 'url.full' => 'http://varnish/api/v1/products', 'http.request.method' => 'GET'],
            array_filter($span->getData(), static fn (mixed $value): bool => null !== $value),
        );
    }

    public function testADoctrineSpanKeepsItsPlaceholdersAndLosesItsLiteralsAndParameters(): void
    {
        $prepared = new Span(SpanContext::make()->setOp('db.sql.execute')->setDescription('SELECT id FROM ordering_order WHERE lower(email) = lower(?) AND id > ?')->setData([
            'db.system' => 'postgresql',
            'db.query.parameter.0' => self::EMAIL,
        ]));
        $literal = new Span(SpanContext::make()->setOp('db.sql.query')->setDescription("SELECT 1 FROM customer WHERE email = '".self::EMAIL."'"));
        $event = Event::createTransaction();
        $event->setSpans([$prepared, $literal]);

        $this->filter->filterEvent($event);

        self::assertSame('SELECT id FROM ordering_order WHERE lower(email) = lower(?) AND id > ?', $prepared->getDescription());
        self::assertSame(['db.system' => 'postgresql'], array_filter($prepared->getData(), static fn (mixed $value): bool => null !== $value));
        self::assertSame("SELECT 1 FROM customer WHERE email = '[REDACTED]'", $literal->getDescription());
    }

    /**
     * The lines of the corpus of the log masking (infra/k8s/tests/log-corpus, api.log and postgres.log; the container of
     * the API sees only apps/api, so they are copied here unchanged) as the texts of an event: what is planted in them
     * does not come out, what is needed to diagnose stays.
     *
     * @param list<string> $planted
     * @param list<string> $kept
     */
    #[DataProvider('corpus')]
    public function testTheLinesOfTheLogCorpusAreMasked(string $line, array $planted, array $kept): void
    {
        $masked = $this->filter->mask($line);

        foreach ($planted as $value) {
            self::assertStringNotContainsString($value, $masked, $value.' came out of the filter');
        }
        self::assertDoesNotMatchRegularExpression('/(?<![:\w])::1(?![\w:])/', $masked, 'the address ::1 came out of the filter');
        foreach ($kept as $fragment) {
            self::assertStringContainsString($fragment, $masked, $fragment.' was masked');
        }
    }

    /**
     * @return iterable<string, array{string, list<string>, list<string>}>
     */
    public static function corpus(): iterable
    {
        yield 'api: a Doctrine exception with parameters and DETAIL' => [
            '{"message":"An exception occurred while executing a statement: INSERT INTO customer (email, name, address, total) VALUES (?, ?, ?, ?) with params [\"jan.kowalski@example.pl\", \"Jan Kowalski\", \"ul. Długa 5, 00-001 Warszawa\", 129.99]:\n\nSQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"uniq_customer_email\"\nDETAIL:  Key (email)=(jan.kowalski@example.pl) already exists.","context":{"exception":{"class":"Doctrine\\\\DBAL\\\\Exception\\\\UniqueConstraintViolationException"}},"level":400,"level_name":"ERROR","channel":"doctrine","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}',
            ['jan.kowalski', 'kowalski@', 'example.pl', 'Jan Kowalski', 'ul. Długa 5', '00-001 Warszawa', '129.99'],
            ['"level_name":"ERROR"', '"channel":"doctrine"', 'UniqueConstraintViolationException', 'duplicate key value violates unique constraint', 'SQLSTATE[23505]', 'Key (email)='],
        ];
        yield 'api: the secrets of an authentication' => [
            '{"message":"Authentication failed","context":{"username":"jankowalski_login","password":"Hunter2-S3cretPw","passwd":"v-passwd-1","secret":"v-secret-1","token":"v-token-1","access_token":"v-access-token-1","refresh_token":"v-refresh-token-1","id_token":"'.self::JWT.'","code":"v-code-1","session":"v-session-1","client_secret":"v-client-secret-1","api_key":"v-api-key-1","authorization":"Bearer abc123DEF456ghi789","cookie":"PHPSESSID=7c5d2e9f1a3b4c6d8e0f; shop_session=zzTOPSECRETsession"},"level":300,"level_name":"WARNING","channel":"security","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}',
            ['jankowalski_login', 'Hunter2-S3cretPw', 'v-passwd-1', 'v-secret-1', 'v-token-1', 'v-access-token-1', 'v-refresh-token-1', self::JWT, 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9', 'v-code-1', 'v-session-1', 'v-client-secret-1', 'v-api-key-1', 'abc123DEF456ghi789', '7c5d2e9f1a3b4c6d8e0f', 'zzTOPSECRETsession'],
            ['"message":"Authentication failed"', '"channel":"security"', '"level_name":"WARNING"'],
        ];
        yield 'api: a request with secrets in the query, addresses and headers' => [
            '{"message":"Request finished","context":{"method":"GET","uri":"/api/v1/orders?page=2&code=v-code-query&token=v-token-query&access_token=v-at-query&api_key=v-ak-query&session=v-session-query&client_secret=v-cs-query&refresh_token=v-rt-query&id_token=v-it-query&password=v-pw-query&passwd=v-passwd-query&secret=v-secret-query&authorization=v-auth-query&cookie=v-cookie-query","status_code":403,"client_ip":"203.0.113.45","forwarded_for":"198.51.100.7, 2001:db8:85a3::8a2e:370:7334, fe80::1ff:fe23:4567:890a, ::1","headers":{"Authorization":["Bearer abc123DEF456ghi789"],"Cookie":["PHPSESSID=7c5d2e9f1a3b4c6d8e0f; shop_session=zzTOPSECRETsession"],"Set-Cookie":["shop_session=zzSETCOOKIEsession; Path=/; HttpOnly"],"Proxy-Authorization":["Basic dXNlcjpwYXNzd29yZDEyMw=="],"User-Agent":["Mozilla/5.0"]},"customer":"anna%40example.com"},"level":400,"level_name":"ERROR","channel":"request","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}',
            ['v-code-query', 'v-token-query', 'v-at-query', 'v-ak-query', 'v-session-query', 'v-cs-query', 'v-rt-query', 'v-it-query', 'v-pw-query', 'v-passwd-query', 'v-secret-query', 'v-auth-query', 'v-cookie-query', '203.0.113.45', '198.51.100.7', '2001:db8:85a3', '8a2e:370:7334', 'fe80::', '1ff:fe23', 'abc123DEF456ghi789', '7c5d2e9f1a3b4c6d8e0f', 'zzTOPSECRETsession', 'zzSETCOOKIEsession', 'dXNlcjpwYXNzd29yZDEyMw==', 'anna%40', 'example.com'],
            ['"message":"Request finished"', '"method":"GET"', '"uri":"/api/v1/orders?page=2&', '"status_code":403', '"channel":"request"'],
        ];
        yield 'api: an e-mail with Polish letters and a password in an address' => [
            '{"message":"Sending the order mail to zażółć.gęślą@przyklad.pl failed: smtp://app:SmtpP4ssw0rd@mailpit:1025 refused","context":{},"level":400,"level_name":"ERROR","channel":"mailer","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}',
            ['gęślą', 'przyklad.pl', 'SmtpP4ssw0rd'],
            ['"channel":"mailer"', 'failed: smtp://'],
        ];
        yield 'api: a password in the address of Redis' => [
            '{"message":"Redis is down","context":{"redis":"redis://:RedisP4ssw0rd@redis:6379"},"level":300,"level_name":"WARNING","channel":"cache","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}',
            ['RedisP4ssw0rd'],
            ['"channel":"cache"', 'Redis is down'],
        ];
        yield 'api: tokens in an escaped JSON string' => [
            '{"message":"Keycloak returned 400: {\"error\":\"invalid_grant\",\"access_token\":\"v-escaped-token\",\"refresh_token\":\"v-escaped-refresh\",\"status\":400}","context":{},"level":400,"level_name":"ERROR","channel":"http_client","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}',
            ['v-escaped-token', 'v-escaped-refresh'],
            ['"channel":"http_client"', 'Keycloak returned 400', 'invalid_grant'],
        ];
        yield 'api: lists of values, keys in camelCase and a number' => [
            '{"message":"Request headers","context":{"headers":{"Set-Cookie":["a=1; Path=/","PHPSESSID=abc999secret; Path=/"],"Cookie":["x=1","shop_session=cookie2secretvalue"],"Authorization":["Bearer short1","Basic Zm9vOmJhcjJzZWNyZXQ="]},"accessToken":"camelAccessTokenVal","clientSecret":"camelClientSecretVal","newPassword":"camelNewPasswordVal","sessionId":"camelSessionIdVal","password":12345678,"status_code":429},"level":300,"level_name":"WARNING","channel":"request","datetime":"2026-11-11T10:00:00.123456+00:00","extra":{}}',
            ['abc999secret', 'cookie2secretvalue', 'Zm9vOmJhcjJzZWNyZXQ=', 'short1', 'camelAccessTokenVal', 'camelClientSecretVal', 'camelNewPasswordVal', 'camelSessionIdVal', '12345678'],
            ['"message":"Request headers"', '"status_code":429', '"channel":"request"'],
        ];
        yield 'postgres: a unique violation' => [
            '2026-11-11 10:00:00.123 UTC [87] ERROR:  duplicate key value violates unique constraint "uniq_customer_email"',
            [],
            ['ERROR:  duplicate key value violates unique constraint "uniq_customer_email"'],
        ];
        yield 'postgres: DETAIL of a unique violation' => [
            '2026-11-11 10:00:00.123 UTC [87] DETAIL:  Key (email)=(jan.kowalski@example.pl) already exists.',
            ['jan.kowalski', 'kowalski@', 'example.pl', 'Key (email)=', 'already exists'],
            ['[87] DETAIL:'],
        ];
        yield 'postgres: STATEMENT' => [
            "2026-11-11 10:00:00.123 UTC [87] STATEMENT:  INSERT INTO customer (email, name) VALUES ('jan.kowalski@example.pl', 'Jan Kowalski')",
            ['INSERT INTO customer (email, name) VALUES', 'jan.kowalski', 'Jan Kowalski'],
            ['STATEMENT:'],
        ];
        yield 'postgres: a connection from an IPv4 address' => [
            '2026-11-11 10:00:01.000 UTC [88] LOG:  connection received: host=203.0.113.45 port=51234',
            ['203.0.113.45'],
            ['LOG:  connection received: host='],
        ];
        yield 'postgres: a connection from an IPv6 address' => [
            '2026-11-11 10:00:02.000 UTC [89] LOG:  connection received: host=2001:db8:85a3::8a2e:370:7334 port=51235',
            ['2001:db8:85a3', '8a2e:370:7334'],
            ['LOG:  connection received: host='],
        ];
        yield 'postgres: a failed authentication' => [
            '2026-11-11 10:00:03.000 UTC [90] FATAL:  password authentication failed for user "shop"',
            [],
            ['FATAL:  password authentication failed for user "shop"'],
        ];
        yield 'postgres: DETAIL of a foreign key' => [
            'DETAIL:  Key (sku)=(PL-SECRET-SKU-9) is not present in table "product".',
            ['PL-SECRET-SKU-9', 'Key (sku)=', 'is not present in table'],
            ['DETAIL:'],
        ];
    }

    /**
     * Alloy drops a whole line DETAIL: or STATEMENT: of PostgreSQL, wherever the value in it has a parenthesis.
     */
    #[DataProvider('detailLines')]
    public function testADetailLineOfPostgresLosesItsWholeText(string $text, string $expected): void
    {
        self::assertSame($expected, $this->filter->mask($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function detailLines(): iterable
    {
        yield 'a row with a parenthesis at the start of a line' => [
            'DETAIL:  Failing row contains (0193, Jan Kowalski, ul. Długa 5 (II p.), +48 600 100 200, Warszawa).',
            'DETAIL:  [REDACTED]',
        ];
        yield 'a row after the prefix of a log line' => [
            '2026-11-11 10:00:00.123 UTC [87] DETAIL:  Failing row contains (0193, Jan Kowalski, ul. Długa 5 (II p.), +48 600 100 200, Warszawa).',
            '2026-11-11 10:00:00.123 UTC [87] DETAIL:  [REDACTED]',
        ];
        yield 'a key with a parenthesis in the value' => [
            'DETAIL:  Key (variant_id, period)=(Jan Kowalski (x), y) already exists.',
            'DETAIL:  [REDACTED]',
        ];
        yield 'a detail without values' => [
            'DETAIL:  Process 87 waits for ShareLock on transaction 4711; blocked by process 88.',
            'DETAIL:  [REDACTED]',
        ];
        yield 'a detail in the line of a message of more lines' => [
            "SQLSTATE[23505]: Unique violation\nDETAIL:  Key (email)=(Jan (x) Kowalski) already exists.\npassword=Hunter2",
            "SQLSTATE[23505]: Unique violation\nDETAIL:  [REDACTED]\npassword=[REDACTED]",
        ];
    }

    /**
     * The row and the key of PostgreSQL inside another text (the message of an exception has them on one line, after
     * the code of the error): masked up to the last parenthesis of the line, so a parenthesis in a value does not
     * end the value.
     */
    #[DataProvider('valuesInAnotherText')]
    public function testARowAndAKeyOfPostgresInAnotherTextAreMaskedToTheEndOfTheValue(string $text, string $expected): void
    {
        self::assertSame($expected, $this->filter->mask($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valuesInAnotherText(): iterable
    {
        yield 'a row' => [
            'SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation "customer" violates check constraint "customer_phone_check" Failing row contains (0193, Jan Kowalski, ul. Długa 5 (II p.), +48 600 100 200, Warszawa).',
            'SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation "customer" violates check constraint "customer_phone_check" Failing row contains ([REDACTED]).',
        ];
        yield 'a key' => [
            'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key Key (variant_id, period)=(Jan Kowalski (x), y) already exists.',
            'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key Key (variant_id, period)=([REDACTED]) already exists.',
        ];
        yield 'a row of a value that ends with a parenthesis' => [
            'Failing row contains (1, Jan Kowalski (x)).',
            'Failing row contains ([REDACTED]).',
        ];
        yield 'a row that is cut before its end' => [
            'Failing row contains (1, Jan Kowalski, ul. Dłu',
            'Failing row contains ([REDACTED]',
        ];
    }

    public function testAMessageOfMoreLinesIsMaskedOnEveryLine(): void
    {
        self::assertSame(
            "SQLSTATE[23505]: Unique violation\nDETAIL:  [REDACTED]\npassword=[REDACTED]",
            $this->filter->mask("SQLSTATE[23505]: Unique violation\nDETAIL:  Key (email)=(".self::EMAIL.") already exists.\npassword=Hunter2"),
        );
    }

    public function testATextThatThePatternsCannotReadIsRedactedAsAWhole(): void
    {
        // A pattern that fails (here: the backtracking limit of PCRE) never lets the text through unmasked.
        $jit = ini_set('pcre.jit', '0');
        $limit = ini_set('pcre.backtrack_limit', '1');
        try {
            $masked = $this->filter->mask('Sent to '.self::EMAIL);
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }

        self::assertSame('[REDACTED]', $masked);
    }

    public function testATextWithoutAnythingToMaskStaysAsItIs(): void
    {
        self::assertSame('Produkt „Kubek” nie istnieje: zażółć gęślą jaźń', $this->filter->mask('Produkt „Kubek” nie istnieje: zażółć gęślą jaźń'));
    }
}
