<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Cache;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * StorefrontCache over HTTP. Varnish: PURGE with `xkey-purge` (a hard purge, see infra/varnish/default.vcl) and the
 * `X-Purge-Token` secret. The store: POST /api/revalidate with the tags and the bearer secret. Each request has its
 * own limits instead of the short defaults of the application's client: Varnish answers at once, the store may first
 * compile the route (`next dev`).
 */
final readonly class HttpStorefrontCache implements StorefrontCache
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $varnishUrl,
        #[\SensitiveParameter] private string $purgeSecret,
        private string $storeUrl,
        #[\SensitiveParameter] private string $revalidateSecret,
        private float $varnishTimeout = 5.0,
        private float $storeTimeout = 30.0,
    ) {
    }

    public function invalidateProduct(string $productId): void
    {
        $tags = [CacheTags::product($productId)];
        $this->purge($tags);
        $this->revalidate($tags);
    }

    public function purgeAll(): void
    {
        $this->purge([CacheTags::CATALOG]);
    }

    public function revalidateAll(): void
    {
        $this->revalidate([CacheTags::CATALOG]);
    }

    /**
     * @param non-empty-list<string> $tags
     */
    private function purge(array $tags): void
    {
        $this->send('Varnish purge', 'PURGE', rtrim($this->varnishUrl, '/').'/', [
            'headers' => ['xkey-purge' => implode(' ', $tags), 'X-Purge-Token' => $this->purgeSecret],
            'timeout' => $this->varnishTimeout,
            'max_duration' => $this->varnishTimeout,
        ]);
    }

    /**
     * @param non-empty-list<string> $tags
     */
    private function revalidate(array $tags): void
    {
        $this->send('store revalidation', 'POST', rtrim($this->storeUrl, '/').'/api/revalidate', [
            'auth_bearer' => $this->revalidateSecret,
            'json' => ['tags' => $tags],
            'timeout' => $this->storeTimeout,
            'max_duration' => $this->storeTimeout,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @throws StorefrontCacheFailure
     */
    private function send(string $layer, string $method, string $url, array $options): void
    {
        try {
            $status = $this->httpClient->request($method, $url, $options)->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new StorefrontCacheFailure(\sprintf('The %s failed: %s', $layer, $e->getMessage()), previous: $e);
        }
        if (200 !== $status) {
            throw new StorefrontCacheFailure(\sprintf('The %s failed: HTTP %d.', $layer, $status));
        }
    }
}
