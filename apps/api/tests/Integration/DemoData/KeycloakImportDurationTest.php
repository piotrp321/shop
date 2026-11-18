<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Accounts\KeycloakAdminFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A `partialImport` of a batch is one transaction of Keycloak and sends nothing until it ends, which on a loaded cluster
 * takes much longer than the 5 s that the shared HTTP client (framework.yaml) gives every request. The endpoint here is
 * a local server that answers the token at once and the import only after 6.5 s of silence.
 */
final class KeycloakImportDurationTest extends KernelTestCase
{
    /** @var resource|null */
    private $server;
    private string $router = '';

    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ('' !== $this->router) {
            @unlink($this->router);
        }
        parent::tearDown();
    }

    public function testAnImportThatAnswersAfterLongerThanTheLimitOfTheSharedClientIsNotCutOff(): void
    {
        $port = $this->startSlowServer();
        $factory = self::getContainer()->get('test.'.KeycloakAdminFactory::class);
        self::assertInstanceOf(KeycloakAdminFactory::class, $factory);
        $admin = $factory->connect('http://127.0.0.1:'.$port, 'admin', 'secret');

        $started = microtime(true);
        $response = $admin->request('POST', '/shop/partialImport', ['json' => ['ifResourceExists' => 'SKIP', 'users' => []]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['added' => 0, 'results' => []], $response->toArray());
        self::assertGreaterThan(6.0, microtime(true) - $started, 'The answer must have taken longer than the 5 s of the shared client.');
    }

    public function testAHangingKeycloakStillEndsTheRequestWithinMinutes(): void
    {
        $idle = self::getContainer()->getParameter('app.demo_data.keycloak_idle_timeout');
        $total = self::getContainer()->getParameter('app.demo_data.keycloak_max_duration');

        self::assertIsNumeric($idle);
        self::assertIsNumeric($total);
        self::assertGreaterThan(5, $idle, 'A batch of an import must fit in the idle time.');
        self::assertGreaterThan(0, $total, '0 would mean no limit of the whole request.');
        self::assertLessThanOrEqual(900, $total, 'A hanging Keycloak must fail the step within minutes.');
        self::assertGreaterThanOrEqual($idle, $total);
    }

    private function startSlowServer(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, (string) $error);
        $name = (string) stream_socket_get_name($socket, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($socket);

        $this->router = sys_get_temp_dir().'/keycloak-slow-'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($this->router, <<<'PHP'
            <?php
            header('Content-Type: application/json');
            if (str_contains($_SERVER['REQUEST_URI'], '/protocol/openid-connect/token')) {
                echo '{"access_token":"token","expires_in":3600}';

                return;
            }
            usleep(6_500_000);
            echo '{"added":0,"results":[]}';
            PHP);
        $server = proc_open(
            [\PHP_BINARY, '-S', '127.0.0.1:'.$port, $this->router],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($server);
        $this->server = $server;
        for ($try = 0; $try < 50; ++$try) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
            if (false !== $connection) {
                fclose($connection);

                return $port;
            }
            usleep(100_000);
        }
        self::fail('The local server did not start.');
    }
}
