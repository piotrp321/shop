<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Every response carries X-Correlation-ID with a UUIDv7 the API made up (spec: docs/features/hardening/spec.md, D9);
 * the header of the request is not trusted.
 */
final class CorrelationIdTest extends WebTestCase
{
    private const string HEADER = 'X-Correlation-ID';

    /** The limit of the order placement in `when@test` (config/packages/rate_limiter.yaml). */
    private const int ORDER_LIMIT = 10;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // The state of the limiter lives as long as the kernel.
        $this->client->disableReboot();
    }

    public function testASuccessfulResponseHasAnIdentifier(): void
    {
        $this->client->request('GET', '/health');

        self::assertResponseStatusCodeSame(200);
        $this->assertUuidV7($this->identifier());
    }

    public function testAnUnknownPathAnAnonymousStaffRequestAndAWrongMethodHaveOneToo(): void
    {
        $this->client->request('GET', '/api/v1/nothing-here');
        self::assertResponseStatusCodeSame(404);
        $this->assertUuidV7($this->identifier());

        $this->client->request('GET', '/api/v1/admin/products');
        self::assertResponseStatusCodeSame(401);
        $this->assertUuidV7($this->identifier());

        $this->client->request('DELETE', '/health');
        self::assertResponseStatusCodeSame(405);
        $this->assertUuidV7($this->identifier());
    }

    public function testATooManyRequestsResponseHasOne(): void
    {
        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.77');
        for ($attempt = 1; $attempt <= self::ORDER_LIMIT; ++$attempt) {
            $this->client->jsonRequest('POST', '/api/v1/orders', []);
            self::assertResponseStatusCodeSame(422);
        }

        $this->client->jsonRequest('POST', '/api/v1/orders', []);

        self::assertResponseStatusCodeSame(429);
        $this->assertUuidV7($this->identifier());
    }

    public function testTheHeaderOfTheRequestIsIgnored(): void
    {
        $this->client->request('GET', '/health', server: ['HTTP_X_CORRELATION_ID' => 'chosen-by-the-caller']);

        $id = $this->identifier();
        self::assertNotSame('chosen-by-the-caller', $id);
        $this->assertUuidV7($id);
    }

    public function testEachRequestGetsItsOwnIdentifier(): void
    {
        $this->client->request('GET', '/health');
        $first = $this->identifier();
        $this->client->request('GET', '/health');

        self::assertNotSame($first, $this->identifier());
    }

    private function identifier(): string
    {
        $id = $this->client->getResponse()->headers->get(self::HEADER);
        self::assertIsString($id, 'the response has '.self::HEADER);

        return $id;
    }

    private function assertUuidV7(string $id): void
    {
        self::assertTrue(Uuid::isValid($id), $id.' is a UUID');
        self::assertSame('7', $id[14], $id.' is a UUIDv7');
    }
}
