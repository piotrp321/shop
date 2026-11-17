<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A session of the administrator of Keycloak (the admin REST API): the token comes from the realm master with the
 * client admin-cli and the login and password of the administrator, and is taken anew shortly before it expires (the
 * token of master lives a minute, an import of a million accounts many). The password never leaves this class, and no
 * message carries it.
 */
final class KeycloakAdmin
{
    /** A token this close to its expiry is not used for a new request. */
    private const int EXPIRY_MARGIN_SECONDS = 15;
    private const float REQUEST_TIMEOUT = 120.0;

    private ?string $token = null;
    private float $expiresAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $url,
        private readonly string $user,
        #[\SensitiveParameter]
        private readonly string $password,
    ) {
    }

    /**
     * A directory of the accounts of one realm.
     */
    public function realm(string $realm): KeycloakRealmDirectory
    {
        return new KeycloakRealmDirectory($this, $realm);
    }

    /**
     * A request to the admin REST API, under /admin/realms; the path is relative to it.
     *
     * @param array<string, mixed> $options
     *
     * @throws \RuntimeException when no token can be taken
     */
    public function request(string $method, string $path, array $options = []): ResponseInterface
    {
        $headers = \is_array($options['headers'] ?? null) ? $options['headers'] : [];

        return $this->http->request($method, rtrim($this->url, '/').'/admin/realms'.$path, [
            'timeout' => self::REQUEST_TIMEOUT,
            ...$options,
            'headers' => [...$headers, 'Authorization' => 'Bearer '.$this->token()],
        ]);
    }

    private function token(): string
    {
        if (null !== $this->token && microtime(true) < $this->expiresAt - self::EXPIRY_MARGIN_SECONDS) {
            return $this->token;
        }
        try {
            $response = $this->http->request('POST', rtrim($this->url, '/').'/realms/master/protocol/openid-connect/token', [
                'body' => ['grant_type' => 'password', 'client_id' => 'admin-cli', 'username' => $this->user, 'password' => $this->password],
                'timeout' => 30.0,
            ]);
            $status = $response->getStatusCode();
            $body = 200 === $status ? $response->toArray(false) : [];
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException(\sprintf('Keycloak at %s does not answer: %s', $this->url, $e->getMessage()), 0, $e);
        }
        $token = $body['access_token'] ?? null;
        $lifetime = $body['expires_in'] ?? null;
        if (!\is_string($token) || !\is_int($lifetime)) {
            throw new \RuntimeException(\sprintf('Keycloak at %s refused the login of the administrator %s (HTTP %d).', $this->url, $this->user, $status));
        }
        $this->token = $token;
        $this->expiresAt = microtime(true) + $lifetime;

        return $token;
    }
}
