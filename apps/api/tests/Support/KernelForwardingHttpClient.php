<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Kernel;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The HTTP client of the calls between the shop and the payment simulator in the tests (spec: "Testy wywołań HTTP
 * dostawców"): a MockHttpClient that does not leave the process but hands every request, whatever its host, to
 * another instance of the `test` kernel and returns its response. The other instance has its own container and
 * EntityManager, so a `flush` or an error on the called side never touches the UnitOfWork of the caller; the
 * database is the same (the connection of DAMA, the transaction of the test), so what one side stores the other
 * reads. A test of a failing provider replaces the client with a MockHttpClient of constant responses.
 *
 * The request is built from the client's normalized options (the body is already a string, `auth_basic` and
 * `auth_bearer` are already in the headers; a form body also fills the request parameters, as PHP does). Redirects are followed by MockHttpClient as by any client.
 */
final class KernelForwardingHttpClient
{
    /**
     * @param string $environment the environment of the other kernel: the one of the caller, so `test_messaging` tests
     *                            reach real Doctrine transports on both sides
     */
    public static function create(string $environment = 'test'): MockHttpClient
    {
        return new MockHttpClient(static fn (string $method, string $url, array $options): ResponseInterface => self::forward($environment, $method, $url, $options));
    }

    /**
     * @param array<mixed> $options
     */
    private static function forward(string $environment, string $method, string $url, array $options): ResponseInterface
    {
        $server = [];
        /** @var array<string, list<string>> $headers */
        $headers = $options['normalized_headers'] ?? [];
        foreach ($headers as $lines) {
            foreach ($lines as $line) {
                [$name, $value] = explode(':', $line, 2) + [1 => ''];
                $key = strtoupper(str_replace('-', '_', trim($name)));
                $server[\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = ltrim($value);
            }
        }
        $body = $options['body'] ?? '';
        \assert(\is_string($body) || $body instanceof \Closure);
        if ($body instanceof \Closure) {
            $content = '';
            while (\is_string($chunk = $body(16_372)) && '' !== $chunk) {
                $content .= $chunk;
            }
            $body = $content;
        }

        // PHP fills $_POST from a form body; Request::create takes the parameters apart from the content.
        $parameters = [];
        if (str_starts_with(strtolower($server['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded')) {
            parse_str($body, $parameters);
        }

        $request = Request::create($url, $method, $parameters, [], [], $server, $body);
        $kernel = new Kernel($environment, false);
        $kernel->boot();
        try {
            $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
            $kernel->terminate($request, $response);
        } finally {
            $kernel->shutdown();
        }

        return new MockResponse((string) $response->getContent(), [
            'http_code' => $response->getStatusCode(),
            'response_headers' => $response->headers->allPreserveCase(),
        ]);
    }
}
