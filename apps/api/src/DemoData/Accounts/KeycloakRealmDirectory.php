<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The accounts of one realm through the admin REST API of Keycloak (the adapter of AccountDirectory). The import is a
 * `partialImport` with `ifResourceExists: SKIP`: each request is one transaction of Keycloak (a rejected batch writes no
 * account), the accounts carry their computed id, `enabled: false`, no credentials and no attributes, the group in
 * `groups` and the role in `realmRoles`, so membership and role come with the account. Measured on Keycloak 26.6.0:
 * an id taken anywhere rejects the batch with 409, an account skipped only for its e-mail address with 500 (the lookup
 * of the id of the skipped account goes by username), and a skipped account has the id of the account with its username.
 */
final readonly class KeycloakRealmDirectory implements AccountDirectory
{
    /** The statuses of a whole batch rejected by its content, explained account by account by the reconciliation. */
    private const array REJECTIONS = [409, 500];
    /** More groups than an account of the generator could have; a page of the groups of an account. */
    private const int GROUPS_PAGE = 1000;

    public function __construct(
        private KeycloakAdmin $admin,
        private string $realm,
    ) {
    }

    public function findGroup(string $name): ?string
    {
        $groups = $this->json('GET', '/groups', ['query' => ['search' => $name, 'exact' => 'true', 'briefRepresentation' => 'true']]);
        foreach ($groups as $group) {
            if (\is_array($group) && ($group['name'] ?? null) === $name && ($group['path'] ?? null) === '/'.$name && \is_string($group['id'] ?? null)) {
                return $group['id'];
            }
        }

        return null;
    }

    public function createGroup(string $name, string $description): string
    {
        $response = $this->send('POST', '/groups', ['json' => ['name' => $name, 'attributes' => ['description' => [$description]]]]);
        $status = $this->status($response);
        if (201 === $status) {
            $location = $response->getHeaders(false)['location'][0] ?? '';

            return basename($location);
        }
        // Created meanwhile by another run.
        if (409 === $status && null !== $id = $this->findGroup($name)) {
            return $id;
        }

        throw $this->failure('POST', '/groups', $status);
    }

    public function groupMembers(string $groupId, int $first, int $max): array
    {
        $members = [];
        foreach ($this->json('GET', '/groups/'.rawurlencode($groupId).'/members', ['query' => ['first' => $first, 'max' => $max, 'briefRepresentation' => 'false']]) as $user) {
            $members[] = self::account($user);
        }

        return $members;
    }

    public function roleMembers(string $role, int $first, int $max): array
    {
        $ids = [];
        foreach ($this->json('GET', '/roles/'.rawurlencode($role).'/users', ['query' => ['first' => $first, 'max' => $max, 'briefRepresentation' => 'true']]) as $user) {
            $ids[] = self::account($user)->id;
        }

        return $ids;
    }

    public function groupsOf(string $userId): ?array
    {
        $path = '/users/'.rawurlencode($userId).'/groups';
        $response = $this->send('GET', $path, ['query' => ['first' => 0, 'max' => self::GROUPS_PAGE, 'briefRepresentation' => 'true']]);
        $status = $this->status($response);
        if (404 === $status) {
            return null;
        }
        if (200 !== $status) {
            throw $this->failure('GET', $path, $status);
        }
        $ids = [];
        foreach ($this->decode($response, 'GET', $path) as $group) {
            if (\is_array($group) && \is_string($group['id'] ?? null)) {
                $ids[] = $group['id'];
            }
        }

        return $ids;
    }

    public function delete(string $userId): bool
    {
        $path = '/users/'.rawurlencode($userId);
        $status = $this->status($this->send('DELETE', $path));

        return match ($status) {
            204 => true,
            404 => false,
            default => throw $this->failure('DELETE', $path, $status),
        };
    }

    public function import(iterable $batches, string $groupName, int $streams): \Generator
    {
        $pending = (static fn (): \Generator => yield from $batches)();
        // The requests run at the same time (the client sends them as they are made) and are awaited in the order they
        // were made: while the oldest is awaited, the others go on.
        /** @var list<array{ResponseInterface, list<GeneratedAccount>}> $inFlight */
        $inFlight = [];
        try {
            while (true) {
                while (\count($inFlight) < $streams && $pending->valid()) {
                    $batch = $pending->current();
                    $pending->next();
                    $response = $this->send('POST', '/partialImport', ['json' => [
                        'ifResourceExists' => 'SKIP',
                        'users' => array_map(static fn (GeneratedAccount $account): array => self::representation($account, $groupName), $batch),
                    ]]);
                    $inFlight[] = [$response, $batch];
                }
                if ([] === $inFlight) {
                    return;
                }

                [$response, $batch] = array_shift($inFlight);

                yield $batch => $this->outcome($response);
            }
        } finally {
            foreach ($inFlight as [$response]) {
                $response->cancel();
            }
        }
    }

    public function findByUsername(string $username): ?DirectoryAccount
    {
        return $this->findOne(['username' => $username]);
    }

    public function findByEmail(string $email): ?DirectoryAccount
    {
        return $this->findOne(['email' => $email]);
    }

    /**
     * @param array<string, string> $query
     */
    private function findOne(array $query): ?DirectoryAccount
    {
        $users = $this->json('GET', '/users', ['query' => [...$query, 'exact' => 'true', 'briefRepresentation' => 'true']]);

        return isset($users[0]) ? self::account($users[0]) : null;
    }

    private function outcome(ResponseInterface $response): ImportOutcome
    {
        $status = $this->status($response);
        if (\in_array($status, self::REJECTIONS, true)) {
            return ImportOutcome::rejected($status);
        }
        $body = $this->decode($response, 'POST', '/partialImport');
        $added = $body['added'] ?? null;
        $results = $body['results'] ?? null;
        if (!\is_int($added) || !\is_array($results)) {
            throw new \RuntimeException(\sprintf('The import into the realm %s gave an answer without a result.', $this->realm));
        }
        $skipped = [];
        foreach ($results as $result) {
            if (\is_array($result) && 'SKIPPED' === ($result['action'] ?? null) && \is_string($result['resourceName'] ?? null)) {
                $id = $result['id'] ?? null;
                $skipped[$result['resourceName']] = \is_string($id) ? $id : null;
            }
        }

        return ImportOutcome::imported($added, $skipped);
    }

    /**
     * @return array<string, mixed>
     */
    private static function representation(GeneratedAccount $account, string $groupName): array
    {
        $user = [
            'id' => $account->id,
            'username' => $account->username,
            'firstName' => $account->firstName,
            'lastName' => $account->lastName,
            'enabled' => false,
            'emailVerified' => false,
            'groups' => ['/'.$groupName],
        ];
        if (null !== $account->email) {
            $user['email'] = $account->email;
        }
        if (null !== $account->role) {
            $user['realmRoles'] = [$account->role];
        }

        return $user;
    }

    private static function account(mixed $user): DirectoryAccount
    {
        if (!\is_array($user) || !\is_string($user['id'] ?? null) || !\is_string($user['username'] ?? null)) {
            throw new \RuntimeException('Keycloak listed an account without an id or a username.');
        }
        $text = static fn (string $key): ?string => \is_string($user[$key] ?? null) ? $user[$key] : null;

        return new DirectoryAccount($user['id'], $user['username'], $text('email'), $text('firstName'), $text('lastName'), true === ($user['enabled'] ?? null));
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function json(string $method, string $path, array $options = []): array
    {
        $response = $this->send($method, $path, $options);
        $status = $this->status($response);
        if (200 !== $status) {
            throw $this->failure($method, $path, $status);
        }

        return $this->decode($response, $method, $path);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $path, array $options = []): ResponseInterface
    {
        try {
            return $this->admin->request($method, '/'.rawurlencode($this->realm).$path, $options);
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException(\sprintf('%s %s in the realm %s failed: %s', $method, $path, $this->realm, $e->getMessage()), 0, $e);
        }
    }

    private function status(ResponseInterface $response): int
    {
        try {
            return $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException(\sprintf('A request to the realm %s failed: %s', $this->realm, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @return array<mixed>
     */
    private function decode(ResponseInterface $response, string $method, string $path): array
    {
        try {
            return $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException(\sprintf('%s %s in the realm %s gave an answer that is not JSON: %s', $method, $path, $this->realm, $e->getMessage()), 0, $e);
        }
    }

    private function failure(string $method, string $path, int $status): \RuntimeException
    {
        return new \RuntimeException(match ($status) {
            404 => \sprintf('%s %s: the realm %s or the resource does not exist (HTTP 404).', $method, $path, $this->realm),
            default => \sprintf('%s %s in the realm %s failed with HTTP %d.', $method, $path, $this->realm, $status),
        });
    }
}
