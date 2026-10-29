<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health\Probe;

use App\Shared\Infrastructure\Health\ConcurrentServiceProbe;
use App\Shared\Infrastructure\Health\ProbeResult;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A service that reports its health with a 2xx answer to a GET. The request has its own limit, whatever the
 * default options of the client are; the answer is never read, only its status.
 */
abstract class HttpProbe implements ConcurrentServiceProbe
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * Path of the health endpoint, starting with a slash.
     */
    abstract protected function path(): string;

    /**
     * Extra request options, e.g. credentials.
     *
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        return [];
    }

    final public function start(): \Closure
    {
        try {
            $response = $this->client->request('GET', rtrim($this->baseUrl, '/').$this->path(), [
                ...$this->options(),
                'timeout' => self::LIMIT_SECONDS,
                'max_duration' => self::LIMIT_SECONDS,
            ]);
            // The transfer only begins once the client is driven: do it now, so that the clock of the limit and the
            // connection start together with the request and not when its answer is awaited.
            foreach ($this->client->stream($response, 0.0) as $chunk) {
                $chunk->isTimeout(); // throws when the request has already failed
                break;
            }
        } catch (ExceptionInterface) {
            return static fn (): ProbeResult => ProbeResult::down();
        }

        return static function () use ($response): ProbeResult {
            try {
                $status = $response->getStatusCode();
                $seconds = $response->getInfo('total_time');
            } catch (ExceptionInterface) {
                return ProbeResult::down();
            } finally {
                $response->cancel();
            }

            return $status >= 200 && $status < 300
                ? ProbeResult::upIn((int) round((\is_float($seconds) ? $seconds : 0.0) * 1000))
                : ProbeResult::down();
        };
    }

    final public function probe(): ProbeResult
    {
        return ($this->start())();
    }
}
