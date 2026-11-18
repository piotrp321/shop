<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

/**
 * The accounts of one realm, as the reconciliation needs them (KeycloakAdmin is the adapter, the admin REST of
 * Keycloak). Every method fails with a RuntimeException when the directory cannot answer.
 */
interface AccountDirectory
{
    /**
     * @return string|null the id of the top-level group with the name
     */
    public function findGroup(string $name): ?string;

    /**
     * Creates the top-level group with the description as its attribute `description`.
     *
     * @return string the id of the new group
     */
    public function createGroup(string $name, string $description): string;

    /**
     * One page of the members of a group, in a stable order.
     *
     * @return list<DirectoryAccount>
     */
    public function groupMembers(string $groupId, int $first, int $max): array;

    /**
     * One page of the ids of the accounts with the realm role, in a stable order.
     *
     * @return list<string>
     */
    public function roleMembers(string $role, int $first, int $max): array;

    /**
     * @return list<string>|null the ids of the groups of the account, null when it does not exist
     */
    public function groupsOf(string $userId): ?array;

    /**
     * @return bool false when the account did not exist
     */
    public function delete(string $userId): bool;

    /**
     * Imports the batches (partial import, existing resources skipped), up to `streams` requests at a time, each batch
     * in one transaction. The accounts are disabled, without credentials and attributes, members of the group and, with a
     * role, holders of that realm role. Yields every batch with its outcome when its request ends.
     *
     * @param iterable<list<GeneratedAccount>> $batches
     *
     * @return \Generator<list<GeneratedAccount>, ImportOutcome>
     */
    public function import(iterable $batches, string $groupName, int $streams): \Generator;

    public function findByUsername(string $username): ?DirectoryAccount;

    public function findByEmail(string $email): ?DirectoryAccount;
}
