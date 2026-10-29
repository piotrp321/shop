<?php

declare(strict_types=1);

namespace App\Tests\Support\Search;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 decorator that sends a matching request, so Elasticsearch executes it, then reports a network error
 * instead of the response: the ambiguous outcome of a timeout or a dropped connection.
 */
final class ResponseDroppingHttpClient implements ClientInterface
{
    public int $dropped = 0;
    private bool $connectionLost = false;

    /**
     * @param \Closure(RequestInterface): bool $matches
     * @param bool                             $dropEverythingAfter once a response is dropped, fail every later request
     *                                                              without sending it (the cluster stays unreachable)
     */
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly \Closure $matches,
        private readonly bool $dropEverythingAfter = false,
    ) {
    }

    public static function aliasSwitches(ClientInterface $inner, bool $dropEverythingAfter = false): self
    {
        return new self(
            $inner,
            static fn (RequestInterface $request): bool => 'POST' === $request->getMethod() && '/_aliases' === $request->getUri()->getPath(),
            $dropEverythingAfter,
        );
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->connectionLost) {
            throw self::networkError($request);
        }

        $response = $this->inner->sendRequest($request);
        if (!($this->matches)($request)) {
            return $response;
        }

        ++$this->dropped;
        $this->connectionLost = $this->dropEverythingAfter;

        throw self::networkError($request);
    }

    private static function networkError(RequestInterface $request): NetworkExceptionInterface
    {
        return new class($request) extends \RuntimeException implements NetworkExceptionInterface {
            public function __construct(private readonly RequestInterface $request)
            {
                parent::__construct('The connection was closed before the response arrived.');
            }

            public function getRequest(): RequestInterface
            {
                return $this->request;
            }
        };
    }
}
