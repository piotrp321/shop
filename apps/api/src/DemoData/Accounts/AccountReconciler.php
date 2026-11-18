<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

use App\DemoData\Identity\StaffRoles;

/**
 * The reconciliation of the generated accounts of one realm (spec: "Konta w Keycloak", W1). Every run does the same,
 * whatever the previous one did (another seed, count or fingerprint, an interrupted step, a rejected batch):
 *
 * 1. ensures the group `demo-data-generated` (the mark of ownership: membership comes with the account, in the same
 *    representation of the import, so no account of the generator exists without it);
 * 2. reads the whole member list page by page, changing nothing meanwhile, and compares it with the target set: a member
 *    with an id of the target and the current data (username, e-mail address, names, disabled, and for the staff the
 *    roles of the cycle) is kept, every other member is to be deleted and its number, when it has one, to be imported;
 * 3. deletes those members one by one, each only after reading its groups and confirming the membership (an account that
 *    is no longer a member is skipped and reported, one that no longer exists is skipped); without the group nothing is
 *    deleted, since the group is created empty;
 * 4. imports the missing accounts (existing ones skipped, never overwritten) and checks every skipped one: an account
 *    with the username but another id, or with the id but outside the group, is a conflict. A rejected batch (Keycloak
 *    rejects the whole batch for an id taken anywhere, 409, and for an account skipped only for its e-mail address, 500)
 *    is imported again account by account; a rejected account is a conflict when its username, e-mail address or id
 *    explains it, anything else stops the run.
 *
 * Afterwards the members of the group are exactly the target set and no account outside the group has changed; the
 * conflicts are listed and leave the foreign accounts as they were. The member list is held as binary ids with a 64-bit
 * digest of the data (about 80 bytes per member), the target as the numbers missing from it.
 */
final readonly class AccountReconciler
{
    public const string GROUP = 'demo-data-generated';
    public const string GROUP_DESCRIPTION = 'konta generatora danych demo; nie dodawaj do niej innych kont';
    /** How many ids or numbers a difference shows. */
    private const int SAMPLE = 5;
    private const string OTHER_ID = '#################';

    /**
     * @param positive-int $pageSize  members (and role holders) read per request
     * @param positive-int $batchSize accounts per import request
     * @param positive-int $streams   import requests at a time
     */
    public function __construct(
        private int $pageSize = 1000,
        private int $batchSize = 2000,
        private int $streams = 4,
    ) {
    }

    /**
     * @param \Closure(string, int): void|null $progress called with `deleted` or `imported` and the running total
     *
     * @throws \RuntimeException         when the directory fails or rejects an account for a reason no account explains
     * @throws \InvalidArgumentException when a dictionary of the people is invalid (read when the first account is generated)
     */
    public function reconcile(AccountDirectory $directory, TargetAccounts $target, ?\Closure $progress = null): ReconcileReport
    {
        $groupId = $directory->findGroup(self::GROUP);
        $groupCreated = null === $groupId;
        $groupId ??= $directory->createGroup(self::GROUP, self::GROUP_DESCRIPTION);

        [$outside, $missing, $kept] = $this->compare($directory, $groupId, $target);

        $deleted = 0;
        $notFound = 0;
        $notMembers = [];
        foreach (array_keys($outside) as $key) {
            $id = self::id((string) $key);
            $groups = $directory->groupsOf($id);
            if (null === $groups) {
                ++$notFound;
            } elseif (!\in_array($groupId, $groups, true)) {
                $notMembers[] = $id;
            } elseif ($directory->delete($id)) {
                ++$deleted;
                if (null !== $progress && 0 === $deleted % 1000) {
                    $progress('deleted', $deleted);
                }
            } else {
                ++$notFound;
            }
        }
        unset($outside);

        $imported = 0;
        $conflicts = [];
        foreach ($directory->import($this->batches($target, $missing), self::GROUP, $this->streams) as $batch => $outcome) {
            if (null === $outcome->rejectedWith) {
                $imported += $outcome->added;
                $kept += $this->checkSkipped($directory, $groupId, $batch, $outcome, $conflicts);
            } else {
                // One account explains the rejection of its batch: the others are written one by one.
                $singles = array_map(static fn (GeneratedAccount $account): array => [$account], $batch);
                foreach ($directory->import($singles, self::GROUP, $this->streams) as $single => $one) {
                    if (null === $one->rejectedWith) {
                        $imported += $one->added;
                        $kept += $this->checkSkipped($directory, $groupId, $single, $one, $conflicts);
                    } else {
                        $conflicts[] = $this->explain($directory, $single[0], $one->rejectedWith);
                    }
                }
            }
            if (null !== $progress) {
                $progress('imported', $imported);
            }
        }

        return new ReconcileReport($groupCreated, $kept, $deleted, $notFound, $notMembers, $imported, $conflicts);
    }

    /**
     * What differs between the realm and the target set, read without changing anything (the account checks of
     * demo-data:verify): a missing group, members outside the target set, accounts of the target missing or not current.
     *
     * @return list<string>
     *
     * @throws \RuntimeException         when the directory fails
     * @throws \InvalidArgumentException when a dictionary of the people is invalid
     */
    public function differences(AccountDirectory $directory, TargetAccounts $target): array
    {
        $groupId = $directory->findGroup(self::GROUP);
        if (null === $groupId) {
            return [\sprintf('the group %s does not exist', self::GROUP)];
        }
        [$outside, $missing] = $this->compare($directory, $groupId, $target);

        $differences = [];
        if ([] !== $outside) {
            $sample = array_map(static fn (int|string $key): string => self::id((string) $key), \array_slice(array_keys($outside), 0, self::SAMPLE));
            $differences[] = \sprintf('%d member(s) of the group %s outside the target set or not current, e.g. %s', \count($outside), self::GROUP, implode(', ', $sample));
        }
        if ([] !== $missing) {
            $differences[] = \sprintf('%d account(s) of the target set missing from the group %s or not current, e.g. numbers %s', \count($missing), self::GROUP, implode(', ', \array_slice($missing, 0, self::SAMPLE)));
        }

        return $differences;
    }

    /**
     * @return array{array<string, int>, list<int>, int} the members to delete (binary id => digest), the numbers to import, the members kept
     */
    private function compare(AccountDirectory $directory, string $groupId, TargetAccounts $target): array
    {
        $roles = [];
        if ($target->staff) {
            foreach (array_keys(StaffRoles::rule()) as $role) {
                foreach ($this->pages(fn (int $first): array => $directory->roleMembers($role, $first, $this->pageSize)) as $id) {
                    $roles[$id][] = $role;
                }
            }
        }

        $members = [];
        foreach ($this->pages(fn (int $first): array => $directory->groupMembers($groupId, $first, $this->pageSize)) as $member) {
            $members[self::key($member->id)] = self::digest($member->username, $member->email, $member->firstName, $member->lastName, $member->enabled, $roles[$member->id] ?? []);
        }
        unset($roles);

        $missing = [];
        $kept = 0;
        for ($number = 1; $number <= $target->count; ++$number) {
            $key = self::key($target->id($number));
            if (isset($members[$key])) {
                $account = $target->account($number);
                if ($members[$key] === self::digest($account->username, $account->email, $account->firstName, $account->lastName, false, null === $account->role ? [] : [$account->role])) {
                    unset($members[$key]);
                    ++$kept;
                    continue;
                }
            }
            $missing[] = $number;
        }

        return [$members, $missing, $kept];
    }

    /**
     * Every item of a paged list: the pages are read until one is shorter than the page.
     *
     * @template T
     *
     * @param \Closure(int): list<T> $page
     *
     * @return \Generator<T>
     */
    private function pages(\Closure $page): \Generator
    {
        $first = 0;
        do {
            $items = $page($first);
            yield from $items;
            $first += $this->pageSize;
        } while (\count($items) === $this->pageSize);
    }

    /**
     * @param list<int> $numbers
     *
     * @return \Generator<list<GeneratedAccount>>
     */
    private function batches(TargetAccounts $target, array $numbers): \Generator
    {
        foreach (array_chunk($numbers, $this->batchSize) as $chunk) {
            yield array_map($target->account(...), $chunk);
        }
    }

    /**
     * Checks the skipped accounts of a written batch and adds a conflict for each that is not an account of the generator.
     *
     * @param list<GeneratedAccount> $batch
     * @param list<string>           $conflicts
     *
     * @return int the skipped accounts that are members with their id (written meanwhile by the generator)
     */
    private function checkSkipped(AccountDirectory $directory, string $groupId, array $batch, ImportOutcome $outcome, array &$conflicts): int
    {
        if ([] === $outcome->skipped) {
            return 0;
        }
        $own = 0;
        foreach ($batch as $account) {
            if (!\array_key_exists($account->username, $outcome->skipped)) {
                continue;
            }
            $existing = $outcome->skipped[$account->username];
            if ($existing !== $account->id) {
                $conflicts[] = null === $existing
                    ? $this->explain($directory, $account, null)
                    : \sprintf('%s (id %s): the username is taken by the account %s outside the group %s', $account->username, $account->id, $existing, self::GROUP);
            } elseif (!\in_array($groupId, $directory->groupsOf($account->id) ?? [], true)) {
                $conflicts[] = \sprintf('%s (id %s): the account exists outside the group %s', $account->username, $account->id, self::GROUP);
            } else {
                ++$own;
            }
        }

        return $own;
    }

    /**
     * @throws \RuntimeException when nothing explains the rejection
     */
    private function explain(AccountDirectory $directory, GeneratedAccount $account, ?int $status): string
    {
        $byUsername = $directory->findByUsername($account->username);
        if (null !== $byUsername && $byUsername->id !== $account->id) {
            return \sprintf('%s (id %s): the username is taken by the account %s outside the group %s', $account->username, $account->id, $byUsername->id, self::GROUP);
        }
        $byEmail = null === $account->email ? null : $directory->findByEmail($account->email);
        if (null !== $byEmail && $byEmail->id !== $account->id) {
            return \sprintf('%s (id %s): the e-mail address %s is taken by the account %s (%s)', $account->username, $account->id, $account->email, $byEmail->username, $byEmail->id);
        }
        if (409 === $status) {
            return \sprintf('%s (id %s): the id is taken by an account with another username or in another realm', $account->username, $account->id);
        }

        throw new \RuntimeException(\sprintf('The import of %s (id %s) was rejected%s, and no account explains it.', $account->username, $account->id, null === $status ? '' : ' with HTTP '.$status));
    }

    /**
     * @param list<string> $roles
     */
    private static function digest(string $username, ?string $email, ?string $firstName, ?string $lastName, bool $enabled, array $roles): int
    {
        $digest = unpack('q', hash('xxh3', json_encode([$username, $email, $firstName, $lastName, $enabled, $roles], \JSON_THROW_ON_ERROR), true));

        return \is_array($digest) && \is_int($digest[1]) ? $digest[1] : 0;
    }

    /**
     * The key of an id in the member list: the 16 bytes of a UUID, any other id after a marker of 17 bytes (so it never
     * has the length of a UUID key).
     */
    private static function key(string $id): string
    {
        $hex = str_replace('-', '', strtolower($id));

        return 36 === \strlen($id) && 1 === preg_match('/^[0-9a-f]{32}$/D', $hex) ? (string) hex2bin($hex) : self::OTHER_ID.$id;
    }

    private static function id(string $key): string
    {
        if (16 !== \strlen($key)) {
            return substr($key, \strlen(self::OTHER_ID));
        }
        $hex = bin2hex($key);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
