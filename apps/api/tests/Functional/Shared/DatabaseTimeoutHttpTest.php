<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The time limit of the statements of an HTTP request (spec: docs/features/runtime-config/spec.md, "Limity czasu
 * w bazie"). The kernel does not reboot between the requests and the connection is a real one (outside the DAMA
 * transaction, in which a cancelled statement would abort every later one), so the requests share one connection
 * as they do in the worker mode of FrankenPHP.
 */
final class DatabaseTimeoutHttpTest extends WebTestCase
{
    /** The limit of an HTTP request (spec) and the margin of the measured time. */
    private const float LIMIT = 3.0;
    private const float MARGIN = 1.5;

    protected function tearDown(): void
    {
        if (self::$booted) {
            $connection = self::getContainer()->get('doctrine.dbal.default_connection');
            \assert($connection instanceof Connection);
            $connection->close();
        }
        StaticDriver::setKeepStaticConnections(true);
        parent::tearDown();
    }

    public function testAQueryLongerThanTheLimitIs503InThePolishProblemFormatWithRetryAfterWithinTheLimit(): void
    {
        $client = $this->clientWithItsOwnConnection();

        $startedAt = microtime(true);
        $client->request('GET', '/_test/database/sleep?seconds=6');
        $elapsed = microtime(true) - $startedAt;

        self::assertResponseStatusCodeSame(503);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertTrue($client->getResponse()->headers->has('Retry-After'));
        self::assertGreaterThanOrEqual(1, (int) $client->getResponse()->headers->get('Retry-After'));
        $problem = $this->body($client);
        self::assertSame(503, $problem['status']);
        self::assertSame('Zapytanie trwało zbyt długo i zostało przerwane. Spróbuj ponownie za chwilę.', $problem['detail'] ?? null);
        self::assertGreaterThan(self::LIMIT - 0.2, $elapsed, 'not cancelled before the limit');
        self::assertLessThan(self::LIMIT + self::MARGIN, $elapsed, 'cancelled at the limit, not when the query ended');
    }

    public function testTheConnectionServesTheNextRequestAfterTheCancelledOne(): void
    {
        $client = $this->clientWithItsOwnConnection();
        $client->request('GET', '/_test/database/sleep?seconds=6');
        self::assertResponseStatusCodeSame(503);

        $client->request('GET', '/_test/database/sleep?seconds=0');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(3000, $this->body($client)['statement_timeout_ms']);
    }

    public function testARouteWithTheLongerLimitIsNotCancelledAtTheLimitOfTheOthersAndTheNextRequestHasTheShortOneAgain(): void
    {
        $client = $this->clientWithItsOwnConnection();

        $client->request('GET', '/_test/database/sleep-long?seconds=3.5');
        self::assertResponseStatusCodeSame(200, 'a query of 3.5 s on a route with an exception');
        self::assertSame(90000, $this->body($client)['statement_timeout_ms']);

        $client->request('GET', '/_test/database/sleep?seconds=0');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(3000, $this->body($client)['statement_timeout_ms'], 'the limit of the exception did not pass to the next request on the same connection');

        $client->request('GET', '/_test/database/sleep?seconds=3.5');
        self::assertResponseStatusCodeSame(503, 'the same query on a route without the exception');
    }

    private function clientWithItsOwnConnection(): KernelBrowser
    {
        // The kernel connects on the first query: with the static connections kept, it would get the one of the DAMA transaction.
        StaticDriver::setKeepStaticConnections(false);
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(KernelBrowser $client): array
    {
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($body));

        return $body;
    }
}
