<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Health;

use App\Shared\Infrastructure\Health\ConcurrentServiceProbe;
use App\Shared\Infrastructure\Health\Probe\ElasticsearchProbe;
use App\Shared\Infrastructure\Health\Probe\GarageProbe;
use App\Shared\Infrastructure\Health\Probe\ImgproxyProbe;
use App\Shared\Infrastructure\Health\Probe\KeycloakProbe;
use App\Shared\Infrastructure\Health\Probe\MailpitProbe;
use App\Shared\Infrastructure\Health\Probe\PostgresProbe;
use App\Shared\Infrastructure\Health\Probe\RabbitMqProbe;
use App\Shared\Infrastructure\Health\Probe\RedisProbe;
use App\Shared\Infrastructure\Health\ProbeResult;
use App\Shared\Infrastructure\Health\ServiceProbe;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Every probe against its running service, and against hosts that do not answer (the stack must be up).
 */
final class ProbesTest extends KernelTestCase
{
    /** @var list<resource> */
    private array $silentServers = [];

    protected function tearDown(): void
    {
        foreach ($this->silentServers as $server) {
            fclose($server);
        }
        $this->silentServers = [];
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{class-string<ServiceProbe>, string}>
     */
    public static function probes(): iterable
    {
        yield 'postgres' => [PostgresProbe::class, 'postgres'];
        yield 'redis' => [RedisProbe::class, 'redis'];
        yield 'elasticsearch' => [ElasticsearchProbe::class, 'elasticsearch'];
        yield 'rabbitmq' => [RabbitMqProbe::class, 'rabbitmq'];
        yield 'keycloak' => [KeycloakProbe::class, 'keycloak'];
        yield 'garage' => [GarageProbe::class, 'garage'];
        yield 'imgproxy' => [ImgproxyProbe::class, 'imgproxy'];
        yield 'mailpit' => [MailpitProbe::class, 'mailpit'];
    }

    /**
     * @param class-string<ServiceProbe> $class
     */
    #[DataProvider('probes')]
    public function testProbeIsUpWhenItsServiceRuns(string $class, string $name): void
    {
        self::bootKernel();
        $probe = self::getContainer()->get('test.'.$class);
        self::assertInstanceOf(ServiceProbe::class, $probe);

        $result = $probe->probe();

        self::assertSame($name, $probe->name());
        self::assertTrue($result->up, $name.' should be up');
        self::assertIsInt($result->latencyMs);
        self::assertGreaterThanOrEqual(0, $result->latencyMs);
        self::assertLessThan(1000, $result->latencyMs);
    }

    /**
     * @return iterable<string, array{\Closure(HttpClientInterface, string): ServiceProbe}>
     */
    public static function httpProbes(): iterable
    {
        yield 'elasticsearch' => [static fn (HttpClientInterface $client, string $url): ServiceProbe => new ElasticsearchProbe($client, $url)];
        yield 'rabbitmq' => [static fn (HttpClientInterface $client, string $url): ServiceProbe => new RabbitMqProbe($client, $url, 'user', 'secret')];
        yield 'keycloak' => [static fn (HttpClientInterface $client, string $url): ServiceProbe => new KeycloakProbe($client, $url)];
        yield 'garage' => [static fn (HttpClientInterface $client, string $url): ServiceProbe => new GarageProbe($client, $url)];
        yield 'imgproxy' => [static fn (HttpClientInterface $client, string $url): ServiceProbe => new ImgproxyProbe($client, $url)];
        yield 'mailpit' => [static fn (HttpClientInterface $client, string $url): ServiceProbe => new MailpitProbe($client, $url)];
    }

    /**
     * @param \Closure(HttpClientInterface, string): ServiceProbe $create
     */
    #[DataProvider('httpProbes')]
    public function testHttpProbeOfARefusingHostIsDownWithoutLatency(\Closure $create): void
    {
        $started = microtime(true);
        $result = $create(HttpClient::create(), 'http://127.0.0.1:1')->probe();

        self::assertFalse($result->up);
        self::assertNull($result->latencyMs);
        self::assertLessThan(1.5, microtime(true) - $started);
    }

    /**
     * @param \Closure(HttpClientInterface, string): ServiceProbe $create
     */
    #[DataProvider('httpProbes')]
    public function testHttpProbeOfAHostThatNeverAnswersIsDownWithinTheLimit(\Closure $create): void
    {
        // Even a client without any default timeout must not wait longer than the probe's own limit.
        $started = microtime(true);
        $result = $create(HttpClient::create(), $this->silentServerUrl())->probe();
        $elapsed = microtime(true) - $started;

        self::assertFalse($result->up);
        self::assertNull($result->latencyMs);
        self::assertGreaterThan(0.9, $elapsed);
        self::assertLessThan(1.5, $elapsed);
    }

    public function testHttpProbeIsDownWhenTheServiceAnswersWithAnError(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 503]));

        $result = (new ElasticsearchProbe($client, 'http://elasticsearch:9200'))->probe();

        self::assertFalse($result->up);
        self::assertNull($result->latencyMs);
    }

    public function testHttpProbeIsDownWhenTheConnectionFailsMidRequest(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'connection reset']));

        $result = (new MailpitProbe($client, 'http://mailpit:8025'))->probe();

        self::assertFalse($result->up);
        self::assertNull($result->latencyMs);
    }

    public function testHttpProbesRunInParallel(): void
    {
        $server = $this->listen();
        $url = 'http://'.stream_socket_get_name($server, false);
        // One client, as in the application: its requests are driven together.
        $client = HttpClient::create();
        $probes = [new MailpitProbe($client, $url), new ImgproxyProbe($client, $url), new GarageProbe($client, $url)];

        $started = microtime(true);
        $pending = array_map(static fn (ConcurrentServiceProbe $probe): \Closure => $probe->start(), $probes);
        $results = array_map(static fn (\Closure $await): ProbeResult => $await(), $pending);
        $elapsed = microtime(true) - $started;

        foreach ($results as $result) {
            self::assertFalse($result->up);
        }
        // Three probes that each wait out their 1 s limit take about 1 s together, not 3.
        self::assertLessThan(1.6, $elapsed);
    }

    public function testRedisProbeOfARefusingHostIsDown(): void
    {
        $started = microtime(true);
        $result = (new RedisProbe('127.0.0.1', 1))->probe();

        self::assertFalse($result->up);
        self::assertNull($result->latencyMs);
        self::assertLessThan(1.5, microtime(true) - $started);
    }

    public function testRedisProbeOfAHostThatNeverAnswersIsDownWithinTheLimit(): void
    {
        $url = $this->silentServerUrl();
        $port = (int) substr($url, (int) strrpos($url, ':') + 1);

        $started = microtime(true);
        $result = (new RedisProbe('127.0.0.1', $port))->probe();
        $elapsed = microtime(true) - $started;

        self::assertFalse($result->up);
        self::assertNull($result->latencyMs);
        self::assertGreaterThan(0.9, $elapsed);
        self::assertLessThan(1.5, $elapsed);
    }

    public function testPostgresProbeOfAnUnreachableDatabaseIsDown(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_pgsql', 'host' => '127.0.0.1', 'port' => 1, 'user' => 'x', 'password' => 'x', 'dbname' => 'x']);

        $result = (new PostgresProbe($connection))->probe();

        self::assertFalse($result->up);
        self::assertNull($result->latencyMs);
    }

    /**
     * A local listener that accepts connections (the kernel completes the handshake) and never says a word.
     *
     * @return resource
     */
    private function listen()
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        self::assertNotFalse($server, (string) $error);
        $this->silentServers[] = $server;

        return $server;
    }

    private function silentServerUrl(): string
    {
        $name = stream_socket_get_name($this->listen(), false);
        self::assertIsString($name);

        return 'http://'.$name;
    }
}
