<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Accounts\AccountDirectory;
use App\DemoData\Accounts\DirectoryAccount;
use App\DemoData\Accounts\ImportOutcome;

/**
 * One realm of Keycloak in memory, with the behaviour of the admin REST the reconciliation relies on (measured on
 * Keycloak 26.6.0): a partial import with SKIP is one transaction (a rejected batch creates nothing), skips an account
 * whose username or e-mail address exists, reports the id of the account with the username, rejects the batch with 409
 * when an id exists (in any realm: `foreignIds`) and with 500 when an account is skipped only for its e-mail address (the
 * id lookup by username fails). It records every call that changes something and every page read.
 */
final class InMemoryAccountDirectory implements AccountDirectory
{
    /** @var array<string, array{id: string, username: string, email: ?string, firstName: ?string, lastName: ?string, enabled: bool, groups: list<string>, roles: list<string>}> */
    public array $users = [];
    /** @var array<string, array{name: string, description: string}> */
    public array $groups = [];
    /** @var list<string> ids that exist in another realm */
    public array $foreignIds = [];
    /** @var list<string> */
    public array $deleted = [];
    /** @var list<int> the size of every import request */
    public array $importRequests = [];
    /** @var list<array{int, int}> first and max of every page of members read */
    public array $memberPages = [];
    /** Throws on the import request with this number (from 1): an interrupted step. */
    public ?int $failOnImport = null;
    /** Called with the id before the groups of an account are read for its deletion. */
    public ?\Closure $beforeGroupsOf = null;
    /** Every import request is rejected with this status. */
    public ?int $rejectEveryImportWith = null;

    public function findGroup(string $name): ?string
    {
        foreach ($this->groups as $id => $group) {
            if ($group['name'] === $name) {
                return $id;
            }
        }

        return null;
    }

    public function createGroup(string $name, string $description): string
    {
        $id = 'group-'.(\count($this->groups) + 1);
        $this->groups[$id] = ['name' => $name, 'description' => $description];

        return $id;
    }

    public function groupMembers(string $groupId, int $first, int $max): array
    {
        $this->memberPages[] = [$first, $max];
        $members = array_filter($this->users, static fn (array $user): bool => \in_array($groupId, $user['groups'], true));
        usort($members, static fn (array $a, array $b): int => $a['username'] <=> $b['username']);

        return array_map(self::account(...), \array_slice($members, $first, $max));
    }

    public function roleMembers(string $role, int $first, int $max): array
    {
        $ids = array_keys(array_filter($this->users, static fn (array $user): bool => \in_array($role, $user['roles'], true)));
        sort($ids);

        return \array_slice(array_map('strval', $ids), $first, $max);
    }

    public function groupsOf(string $userId): ?array
    {
        if (null !== $this->beforeGroupsOf) {
            ($this->beforeGroupsOf)($userId);
        }

        return isset($this->users[$userId]) ? $this->users[$userId]['groups'] : null;
    }

    public function delete(string $userId): bool
    {
        if (!isset($this->users[$userId])) {
            return false;
        }
        unset($this->users[$userId]);
        $this->deleted[] = $userId;

        return true;
    }

    public function import(iterable $batches, string $groupName, int $streams): \Generator
    {
        $groupId = $this->findGroup($groupName);
        foreach ($batches as $batch) {
            $this->importRequests[] = \count($batch);
            if (\count($this->importRequests) === $this->failOnImport) {
                throw new \RuntimeException('The step was interrupted.');
            }
            if (null !== $this->rejectEveryImportWith) {
                yield $batch => ImportOutcome::rejected($this->rejectEveryImportWith);
                continue;
            }

            $skipped = [];
            $added = [];
            $outcome = null;
            foreach ($batch as $account) {
                $byUsername = $this->findByUsername($account->username);
                $byEmail = null === $account->email ? null : $this->findByEmail($account->email);
                if (null !== $byUsername) {
                    $skipped[$account->username] = $byUsername->id;
                } elseif (null !== $byEmail) {
                    $outcome = ImportOutcome::rejected(500);
                    break;
                } elseif (isset($this->users[$account->id]) || \in_array($account->id, $this->foreignIds, true) || isset($added[$account->id])) {
                    $outcome = ImportOutcome::rejected(409);
                    break;
                } else {
                    \assert(null !== $groupId, 'A batch with a group that does not exist is rejected by Keycloak.');
                    $added[$account->id] = [
                        'id' => $account->id,
                        'username' => $account->username,
                        'email' => $account->email,
                        'firstName' => $account->firstName,
                        'lastName' => $account->lastName,
                        'enabled' => false,
                        'groups' => [$groupId],
                        'roles' => null === $account->role ? [] : [$account->role],
                    ];
                }
            }
            if (null === $outcome) {
                $this->users = [...$this->users, ...$added];
                $outcome = ImportOutcome::imported(\count($added), $skipped);
            }

            yield $batch => $outcome;
        }
    }

    public function findByUsername(string $username): ?DirectoryAccount
    {
        foreach ($this->users as $user) {
            if ($user['username'] === $username) {
                return self::account($user);
            }
        }

        return null;
    }

    public function findByEmail(string $email): ?DirectoryAccount
    {
        foreach ($this->users as $user) {
            if (null !== $user['email'] && strtolower($user['email']) === strtolower($email)) {
                return self::account($user);
            }
        }

        return null;
    }

    /**
     * An account outside the generator (a real one), with its groups and roles.
     *
     * @param list<string> $groups
     * @param list<string> $roles
     */
    public function addForeign(string $id, string $username, ?string $email = null, array $groups = [], array $roles = [], bool $enabled = true): void
    {
        $this->users[$id] = ['id' => $id, 'username' => $username, 'email' => $email, 'firstName' => 'Ktoś', 'lastName' => 'Inny', 'enabled' => $enabled, 'groups' => $groups, 'roles' => $roles];
    }

    /**
     * A change made by hand in the admin console: the account enabled, a role added, the groups left.
     */
    public function enable(string $id): void
    {
        $this->users[$id]['enabled'] = true;
    }

    public function grantRole(string $id, string $role): void
    {
        $this->users[$id]['roles'] = [...$this->users[$id]['roles'], $role];
    }

    public function leaveGroups(string $id): void
    {
        $this->users[$id]['groups'] = [];
    }

    /**
     * @return list<string> the ids of the members of the group, sorted
     */
    public function memberIds(string $groupName): array
    {
        $groupId = $this->findGroup($groupName);
        $ids = array_keys(array_filter($this->users, static fn (array $user): bool => \in_array($groupId, $user['groups'], true)));
        $ids = array_map('strval', $ids);
        sort($ids);

        return $ids;
    }

    /**
     * @param array{id: string, username: string, email: ?string, firstName: ?string, lastName: ?string, enabled: bool, groups: list<string>, roles: list<string>} $user
     */
    private static function account(array $user): DirectoryAccount
    {
        return new DirectoryAccount($user['id'], $user['username'], $user['email'], $user['firstName'], $user['lastName'], $user['enabled']);
    }
}
