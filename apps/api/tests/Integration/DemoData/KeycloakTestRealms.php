<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Accounts\KeycloakAdmin;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Two temporary realms on the Keycloak of the stack for one test, never the realms shop and backoffice: one like the
 * realm shop (e-mail as username, reset of the password allowed, mail through Mailpit, a public client with the password
 * grant to try a login) and one like the realm backoffice (the four roles of the staff). Names are unique per test, so
 * runs and tests never share them; delete() removes both.
 */
final class KeycloakTestRealms
{
    public const string LOGIN_CLIENT = 'demo-login';
    private const array STAFF_ROLES = ['admin', 'support', 'warehouse', 'marketing'];

    public readonly string $shop;
    public readonly string $backoffice;
    public readonly KeycloakAdmin $admin;

    public function __construct(?HttpClientInterface $http = null)
    {
        $this->admin = self::admin($http);
        $suffix = bin2hex(random_bytes(5));
        $this->shop = 'demo-data-test-shop-'.$suffix;
        $this->backoffice = 'demo-data-test-backoffice-'.$suffix;
        $this->createRealm([
            'realm' => $this->shop,
            'enabled' => true,
            'registrationEmailAsUsername' => true,
            'loginWithEmailAllowed' => true,
            'duplicateEmailsAllowed' => false,
            'resetPasswordAllowed' => true,
            'smtpServer' => ['host' => 'mailpit', 'port' => '1025', 'from' => 'sklep@example.com', 'fromDisplayName' => 'Sklep', 'auth' => 'false', 'ssl' => 'false', 'starttls' => 'false'],
            'clients' => [[
                'clientId' => self::LOGIN_CLIENT,
                'publicClient' => true,
                'directAccessGrantsEnabled' => true,
                'standardFlowEnabled' => true,
                'redirectUris' => ['*'],
            ]],
        ]);
        try {
            $this->createRealm([
                'realm' => $this->backoffice,
                'enabled' => true,
                'roles' => ['realm' => array_map(static fn (string $role): array => ['name' => $role], self::STAFF_ROLES)],
            ]);
        } catch (\Throwable $e) {
            // The constructor failed, so no caller can delete the realm that was created.
            $this->admin->request('DELETE', '/'.$this->shop)->getStatusCode();

            throw $e;
        }
    }

    public static function url(): string
    {
        $url = getenv('DEMO_DATA_KEYCLOAK_URL');

        return \is_string($url) && '' !== $url ? $url : 'http://keycloak:8080';
    }

    /**
     * A session of the administrator of the stack over the given client.
     */
    public static function admin(?HttpClientInterface $http = null): KeycloakAdmin
    {
        $password = getenv('KC_BOOTSTRAP_ADMIN_PASSWORD');
        \assert(\is_string($password) && '' !== $password, 'KC_BOOTSTRAP_ADMIN_PASSWORD is not set.');

        return new KeycloakAdmin($http ?? HttpClient::create(), self::url(), 'admin', $password);
    }

    public function delete(): void
    {
        foreach ([$this->shop, $this->backoffice] as $realm) {
            $this->admin->request('DELETE', '/'.$realm)->getStatusCode();
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    public function json(string $method, string $path, array $options = []): array
    {
        $response = $this->admin->request($method, $path, $options);
        Assert::assertLessThan(300, $response->getStatusCode(), $method.' '.$path);

        return '' === $response->getContent() ? [] : $response->toArray();
    }

    /**
     * Creates an account outside the generator through the admin REST API.
     *
     * @param array<string, mixed> $user
     * @param list<string>         $roles realm roles
     *
     * @return string the id of the account
     */
    public function createUser(string $realm, array $user, array $roles = []): string
    {
        if (isset($user['id'])) {
            // The users endpoint ignores an id: an account with a chosen id comes from a partial import, outside any group.
            $this->json('POST', '/'.$realm.'/partialImport', ['json' => ['ifResourceExists' => 'FAIL', 'users' => [$user + ['realmRoles' => $roles]]]]);
            \assert(\is_string($user['id']));

            return $user['id'];
        }
        $response = $this->admin->request('POST', '/'.$realm.'/users', ['json' => $user]);
        Assert::assertSame(201, $response->getStatusCode(), 'POST users');
        $id = basename($response->getHeaders()['location'][0]);
        if ([] !== $roles) {
            $representations = array_map(function (string $role) use ($realm): array {
                $representation = $this->json('GET', '/'.$realm.'/roles/'.$role);

                return ['id' => $representation['id'], 'name' => $representation['name']];
            }, $roles);
            $this->json('POST', '/'.$realm.'/users/'.$id.'/role-mappings/realm', ['json' => $representations]);
        }

        return $id;
    }

    /**
     * Everything the reconciliation could change on an account: the account, its groups, realm roles and credentials.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $realm, string $id): array
    {
        $response = $this->admin->request('GET', '/'.$realm.'/users/'.$id);
        if (404 === $response->getStatusCode()) {
            return ['missing' => true];
        }

        return [
            'user' => $response->toArray(),
            'groups' => array_column($this->json('GET', '/'.$realm.'/users/'.$id.'/groups'), 'path'),
            'roles' => $this->realmRoles($realm, $id),
            'credentials' => $this->json('GET', '/'.$realm.'/users/'.$id.'/credentials'),
        ];
    }

    /**
     * @return list<string> the names of the realm roles of the account
     */
    public function realmRoles(string $realm, string $id): array
    {
        $roles = [];
        foreach ($this->json('GET', '/'.$realm.'/users/'.$id.'/role-mappings/realm') as $role) {
            Assert::assertIsArray($role);
            Assert::assertIsString($role['name']);
            $roles[] = $role['name'];
        }

        return $roles;
    }

    /**
     * @param array<string, mixed> $representation
     */
    private function createRealm(array $representation): void
    {
        $response = $this->admin->request('POST', '', ['json' => $representation]);
        Assert::assertSame(201, $response->getStatusCode(), 'POST realm');
    }
}
