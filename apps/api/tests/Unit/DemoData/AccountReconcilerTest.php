<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Accounts\AccountGenerator;
use App\DemoData\Accounts\AccountReconciler;
use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Accounts\TargetAccounts;
use PHPUnit\Framework\TestCase;

/**
 * The reconciliation of one realm (spec: "Konta w Keycloak", W1) against Keycloak in memory: after every run the members
 * of the group are exactly the target set with current data, and no account outside the group changes.
 */
final class AccountReconcilerTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../fixtures/demo-data';
    private const string NAMESPACE = '8d3b6f1e-5a27-4c90-b1e4-7f02a96c35d8';
    private const string OTHER_NAMESPACE = 'b3a7d1f0-3c64-4e8b-9a52-1d7e0c5f6a49';
    private const string GROUP = 'demo-data-generated';

    private InMemoryAccountDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new InMemoryAccountDirectory();
    }

    protected function tearDown(): void
    {
        unset($this->directory);
    }

    private static function generator(string $namespace = self::NAMESPACE, ?PersonDictionaries $people = null): AccountGenerator
    {
        return new AccountGenerator($namespace, $people ?? new PersonDictionaries(self::FIXTURES));
    }

    private static function customers(int $count, string $namespace = self::NAMESPACE): TargetAccounts
    {
        return new TargetAccounts(self::generator($namespace), false, $count);
    }

    private static function staff(int $count, string $namespace = self::NAMESPACE): TargetAccounts
    {
        return new TargetAccounts(self::generator($namespace), true, $count);
    }

    private static function reconciler(): AccountReconciler
    {
        return new AccountReconciler(pageSize: 3, batchSize: 4, streams: 2);
    }

    /**
     * @return list<string>
     */
    private static function ids(TargetAccounts $target): array
    {
        $ids = array_map($target->id(...), range(1, $target->count));
        sort($ids);

        return $ids;
    }

    private function assertExactlyTheTarget(TargetAccounts $target): void
    {
        self::assertSame(self::ids($target), $this->directory->memberIds(self::GROUP));
        foreach (range(1, $target->count) as $number) {
            $account = $target->account($number);
            $user = $this->directory->users[$account->id];
            self::assertSame([$account->username, $account->email, $account->firstName, $account->lastName, false], [$user['username'], $user['email'], $user['firstName'], $user['lastName'], $user['enabled']]);
            self::assertSame(null === $account->role ? [] : [$account->role], $user['roles']);
        }
        self::assertSame([], self::reconciler()->differences($this->directory, $target));
    }

    public function testTheFirstRunCreatesTheGroupWithItsDescriptionAndImportsEveryAccountInBatches(): void
    {
        $report = self::reconciler()->reconcile($this->directory, self::customers(10));

        self::assertSame(['name' => self::GROUP, 'description' => 'konta generatora danych demo; nie dodawaj do niej innych kont'], array_values($this->directory->groups)[0]);
        self::assertTrue($report->groupCreated);
        self::assertSame(10, $report->imported);
        self::assertSame([4, 4, 2], $this->directory->importRequests);
        self::assertSame([], $report->conflicts);
        $this->assertExactlyTheTarget(self::customers(10));
    }

    public function testStaffGetTheRoleOfTheirNumber(): void
    {
        self::reconciler()->reconcile($this->directory, self::staff(12));

        $this->assertExactlyTheTarget(self::staff(12));
        self::assertSame(['admin'], $this->directory->users[self::staff(12)->id(10)]['roles']);
        self::assertSame(['support'], $this->directory->users[self::staff(12)->id(11)]['roles']);
    }

    public function testASecondRunWithTheSameOptionsChangesNothing(): void
    {
        self::reconciler()->reconcile($this->directory, self::staff(7));
        $this->directory->importRequests = [];

        $report = self::reconciler()->reconcile($this->directory, self::staff(7));

        self::assertSame([], $this->directory->importRequests, 'no import');
        self::assertSame([], $this->directory->deleted, 'no delete');
        self::assertSame([0, 0, 7, false], [$report->imported, $report->deleted, $report->kept, $report->groupCreated]);
    }

    public function testASmallerCountDeletesTheMembersAboveItAndALargerCountImportsOnlyTheNewOnes(): void
    {
        self::reconciler()->reconcile($this->directory, self::customers(9));

        $smaller = self::reconciler()->reconcile($this->directory, self::customers(5));

        self::assertSame(4, $smaller->deleted);
        self::assertEqualsCanonicalizing(array_map(self::customers(9)->id(...), range(6, 9)), $this->directory->deleted);
        $this->assertExactlyTheTarget(self::customers(5));

        $this->directory->importRequests = [];
        $larger = self::reconciler()->reconcile($this->directory, self::customers(11));

        self::assertSame([6, 0], [$larger->imported, $larger->deleted]);
        self::assertSame([4, 2], $this->directory->importRequests);
        $this->assertExactlyTheTarget(self::customers(11));
    }

    public function testAnotherNamespaceReplacesEveryMember(): void
    {
        self::reconciler()->reconcile($this->directory, self::customers(6));

        $report = self::reconciler()->reconcile($this->directory, self::customers(6, self::OTHER_NAMESPACE));

        self::assertSame([6, 6], [$report->deleted, $report->imported]);
        $this->assertExactlyTheTarget(self::customers(6, self::OTHER_NAMESPACE));
    }

    public function testAnotherFingerprintReplacesEveryMember(): void
    {
        self::reconciler()->reconcile($this->directory, self::staff(5));
        $copy = sys_get_temp_dir().'/reconciler-'.bin2hex(random_bytes(6));
        mkdir($copy);
        try {
            foreach (['first-names.yaml', 'surnames.yaml', 'places.yaml'] as $file) {
                copy(self::FIXTURES.'/'.$file, $copy.'/'.$file);
            }
            file_put_contents($copy.'/places.yaml', str_replace("  - Polna\n", '', (string) file_get_contents($copy.'/places.yaml')));
            $other = new TargetAccounts(self::generator(self::NAMESPACE, new PersonDictionaries($copy)), true, 5);

            $report = self::reconciler()->reconcile($this->directory, $other);

            self::assertSame(5, $report->deleted);
            $this->assertExactlyTheTarget($other);
        } finally {
            array_map('unlink', glob($copy.'/*') ?: []);
            rmdir($copy);
        }
    }

    public function testTheWholeMemberListIsReadPageByPageBeforeAnyDeletion(): void
    {
        self::reconciler()->reconcile($this->directory, self::customers(10));
        $this->directory->memberPages = [];
        $pagesWhenDeleting = null;
        $this->directory->beforeGroupsOf = function () use (&$pagesWhenDeleting): void {
            $pagesWhenDeleting ??= \count($this->directory->memberPages);
        };

        self::reconciler()->reconcile($this->directory, self::customers(1, self::OTHER_NAMESPACE));

        self::assertSame([[0, 3], [3, 3], [6, 3], [9, 3]], $this->directory->memberPages);
        self::assertSame(4, $pagesWhenDeleting, 'Every page was read before the first deletion.');
        $this->assertExactlyTheTarget(self::customers(1, self::OTHER_NAMESPACE));
    }

    public function testAMemberWithChangedDataIsReplaced(): void
    {
        self::reconciler()->reconcile($this->directory, self::staff(4));
        $enabled = self::staff(4)->id(2);
        $promoted = self::staff(4)->id(3);
        $this->directory->enable($enabled);
        $this->directory->grantRole($promoted, 'marketing');

        $report = self::reconciler()->reconcile($this->directory, self::staff(4));

        self::assertSame([2, 2, 2], [$report->deleted, $report->imported, $report->kept]);
        $this->assertExactlyTheTarget(self::staff(4));
    }

    public function testAccountsOutsideTheGroupSurviveEveryRunUnchanged(): void
    {
        // A real account with a username in the generator format and the id the seed gives number 9, a plain one and
        // an administrator made by create-admin.
        $lookalike = self::customers(9)->account(9);
        $this->directory->addForeign($lookalike->id, (string) $lookalike->email, $lookalike->email);
        $this->directory->addForeign('00000000-0000-4000-8000-000000000001', 'jan.kowalski@example.com', 'jan.kowalski@example.com');
        $this->directory->addForeign('00000000-0000-4000-8000-000000000002', 'admin', 'admin@example.com', roles: ['admin']);
        $foreign = $this->directory->users;

        foreach ([self::customers(5), self::customers(3), self::customers(7, self::OTHER_NAMESPACE), self::customers(5)] as $target) {
            $report = self::reconciler()->reconcile($this->directory, $target);
            self::assertSame([], $report->conflicts);
            $this->assertExactlyTheTarget($target);
            foreach ($foreign as $id => $user) {
                self::assertSame($user, $this->directory->users[$id] ?? null);
            }
        }
    }

    public function testWithoutTheGroupNothingIsDeletedAndTheOldAccountsAreConflicts(): void
    {
        self::reconciler()->reconcile($this->directory, self::customers(6));
        $before = $this->directory->users;
        // Someone deleted the group: the accounts lose their membership.
        $this->directory->groups = [];
        foreach (array_keys($this->directory->users) as $id) {
            $this->directory->leaveGroups($id);
        }

        $report = self::reconciler()->reconcile($this->directory, self::customers(4));

        self::assertSame([], $this->directory->deleted);
        self::assertSame(array_keys($before), array_keys($this->directory->users));
        self::assertTrue($report->groupCreated);
        self::assertCount(4, $report->conflicts);
        self::assertStringContainsString(self::customers(4)->account(1)->username, implode("\n", $report->conflicts));
    }

    public function testAnAccountThatLeftTheGroupBeforeItsDeletionIsSkippedAndReported(): void
    {
        self::reconciler()->reconcile($this->directory, self::customers(4));
        $left = self::customers(4)->id(4);
        $gone = self::customers(4)->id(3);
        $this->directory->beforeGroupsOf = function (string $id) use ($left, $gone): void {
            if ($id === $left) {
                $this->directory->leaveGroups($left);
            }
            if ($id === $gone) {
                unset($this->directory->users[$gone]);
            }
        };

        $report = self::reconciler()->reconcile($this->directory, self::customers(2));

        self::assertSame([0, 1], [$report->deleted, $report->notFound]);
        self::assertSame([$left], $report->notMembers);
        self::assertArrayHasKey($left, $this->directory->users);
    }

    public function testAUsernameTakenOutsideTheGroupIsAConflictAndTheRestIsImported(): void
    {
        $taken = self::customers(6)->account(2);
        $this->directory->addForeign('00000000-0000-4000-8000-000000000003', $taken->username, 'someone@example.com');
        $foreign = $this->directory->users;

        $report = self::reconciler()->reconcile($this->directory, self::customers(6));

        self::assertCount(1, $report->conflicts);
        self::assertStringContainsString($taken->username, $report->conflicts[0]);
        self::assertSame(5, $report->imported);
        self::assertSame($foreign['00000000-0000-4000-8000-000000000003'], $this->directory->users['00000000-0000-4000-8000-000000000003']);
        self::assertNotContains($taken->id, $this->directory->memberIds(self::GROUP));
    }

    public function testAnEmailTakenOutsideTheGroupRejectsTheBatchAndIsAConflictAndTheRestIsImported(): void
    {
        $taken = self::customers(6)->account(3);
        $this->directory->addForeign('00000000-0000-4000-8000-000000000004', 'other.login', $taken->email);

        $report = self::reconciler()->reconcile($this->directory, self::customers(6));

        self::assertCount(1, $report->conflicts);
        self::assertStringContainsString((string) $taken->email, $report->conflicts[0]);
        self::assertStringContainsString('other.login', $report->conflicts[0]);
        self::assertSame(5, $report->imported);
        self::assertSame('other.login', $this->directory->users['00000000-0000-4000-8000-000000000004']['username']);
    }

    public function testAnIdTakenInAnotherRealmRejectsTheBatchAndIsAConflictAndTheRestIsImported(): void
    {
        $colliding = self::customers(10)->account(6);
        $this->directory->foreignIds[] = $colliding->id;

        $report = self::reconciler()->reconcile($this->directory, self::customers(10));

        self::assertCount(1, $report->conflicts);
        self::assertStringContainsString($colliding->id, $report->conflicts[0]);
        self::assertSame(9, $report->imported);
        self::assertNotContains($colliding->id, $this->directory->memberIds(self::GROUP));

        // Once the other account is gone, the next run completes the target.
        $this->directory->foreignIds = [];
        self::assertSame([], self::reconciler()->reconcile($this->directory, self::customers(10))->conflicts);
        $this->assertExactlyTheTarget(self::customers(10));
    }

    public function testARejectionTheAccountsDoNotExplainStopsTheStep(): void
    {
        $this->directory->createGroup(self::GROUP, 'x');
        $this->directory->rejectEveryImportWith = 500;

        $this->expectException(\RuntimeException::class);

        self::reconciler()->reconcile($this->directory, self::customers(3));
    }

    public function testAnInterruptedStepIsCompletedByTheNextRun(): void
    {
        $this->directory->failOnImport = 2;
        try {
            self::reconciler()->reconcile($this->directory, self::customers(10, self::OTHER_NAMESPACE));
            self::fail('The step was not interrupted.');
        } catch (\RuntimeException) {
        }
        self::assertCount(4, $this->directory->memberIds(self::GROUP), 'The first batch was written.');
        $this->directory->failOnImport = null;

        $report = self::reconciler()->reconcile($this->directory, self::customers(10, self::OTHER_NAMESPACE));

        self::assertSame([4, 6], [$report->kept, $report->imported]);
        $this->assertExactlyTheTarget(self::customers(10, self::OTHER_NAMESPACE));
    }

    public function testTheDifferencesListAMissingGroupMissingAccountsAndMembersOutsideTheTarget(): void
    {
        self::assertNotSame([], self::reconciler()->differences($this->directory, self::customers(3)));
        self::assertSame([], $this->directory->groups, 'Reading changes nothing.');

        self::reconciler()->reconcile($this->directory, self::customers(3));
        self::assertSame([], self::reconciler()->differences($this->directory, self::customers(3)));

        $differences = implode("\n", self::reconciler()->differences($this->directory, self::customers(2)));
        self::assertStringContainsString('1 member', $differences);
        $differences = implode("\n", self::reconciler()->differences($this->directory, self::customers(5)));
        self::assertStringContainsString('2 account', $differences);
    }
}
