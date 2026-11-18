<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The session of the administrator of Keycloak for the steps of the accounts, from the options of a command or, without
 * them, from the environment: DEMO_DATA_KEYCLOAK_URL (default http://keycloak:8080), the login admin (as in compose and
 * the Job keycloak-config) and KC_BOOTSTRAP_ADMIN_PASSWORD of the secret of the stack.
 */
final readonly class KeycloakAdminFactory
{
    public const string DEFAULT_USER = 'admin';

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(default:app.demo_data.keycloak_url:DEMO_DATA_KEYCLOAK_URL)%')]
        private string $url,
        #[Autowire('%env(default::KC_BOOTSTRAP_ADMIN_PASSWORD)%')]
        #[\SensitiveParameter]
        private ?string $password,
    ) {
    }

    /**
     * @throws \InvalidArgumentException without a password
     */
    public function connect(?string $url, ?string $user, #[\SensitiveParameter] ?string $password): KeycloakAdmin
    {
        $password ??= $this->password;
        if (null === $password || '' === $password) {
            throw new \InvalidArgumentException('No password of the Keycloak administrator: set KC_BOOTSTRAP_ADMIN_PASSWORD or --keycloak-password.');
        }

        return new KeycloakAdmin($this->http, $url ?? $this->url, $user ?? self::DEFAULT_USER, $password);
    }
}
