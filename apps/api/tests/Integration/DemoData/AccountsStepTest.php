<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Accounts\AccountGenerator;
use App\DemoData\Accounts\AccountReconciler;
use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Accounts\TargetAccounts;
use App\Tests\Integration\Ordering\Mail\MailpitInbox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * demo-data:accounts on the real Keycloak of the stack (spec: "Konta w Keycloak", W1, criterion 7), on two temporary
 * realms per test (KeycloakTestRealms, never the realms shop and backoffice) and with a seed unique per test (the ids of
 * the accounts are global in Keycloak). Small counts, batches of 3 and pages of 2, so every path has several batches and
 * pages.
 */
final class AccountsStepTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__.'/../../../fixtures/demo-data';
    private const array ROLES = ['admin', 'support', 'warehouse', 'marketing'];

    private KeycloakTestRealms $realms;
    private string $seed;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->realms = new KeycloakTestRealms();
        $this->seed = Uuid::v4()->toRfc4122();
    }

    protected function tearDown(): void
    {
        $this->realms->delete();
        // PHPUnit keeps every test object to the end of the run: the session and its HTTP client go now.
        unset($this->realms);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function step(int $customers, int $staff, ?string $seed = null, array $options = []): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel ?? self::bootKernel())->find('demo-data:accounts'));
        $tester->execute($options + [
            '--demo-profile' => 'small',
            '--seed' => $seed ?? $this->seed,
            '--customers' => $customers,
            '--staff' => $staff,
            '--shop-realm' => $this->realms->shop,
            '--backoffice-realm' => $this->realms->backoffice,
            '--batch-size' => 3,
            '--page-size' => 2,
        ]);

        return $tester;
    }

    private function succeeds(int $customers, int $staff, ?string $seed = null): CommandTester
    {
        $tester = $this->step($customers, $staff, $seed);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        return $tester;
    }

    private function generator(?string $seed = null, ?PersonDictionaries $people = null): AccountGenerator
    {
        return new AccountGenerator($seed ?? $this->seed, $people ?? new PersonDictionaries(self::FIXTURES));
    }

    /**
     * @return list<string> the ids of the members of the group, sorted
     */
    private function memberIds(string $realm): array
    {
        $groupId = null;
        foreach ($this->realms->json('GET', '/'.$realm.'/groups', ['query' => ['search' => AccountReconciler::GROUP, 'exact' => 'true']]) as $group) {
            if (\is_array($group) && '/'.AccountReconciler::GROUP === $group['path'] && \is_string($group['id'])) {
                $groupId = $group['id'];
            }
        }
        if (null === $groupId) {
            return [];
        }
        $ids = [];
        foreach ($this->realms->json('GET', '/'.$realm.'/groups/'.$groupId.'/members', ['query' => ['max' => 1000]]) as $member) {
            \assert(\is_array($member) && \is_string($member['id']));
            $ids[] = $member['id'];
        }
        sort($ids);

        return $ids;
    }

    /**
     * The members of the group of both realms are exactly the target sets, with the data of the generator.
     */
    private function assertExactlyTheTarget(int $customers, int $staff, ?AccountGenerator $generator = null): void
    {
        $generator ??= $this->generator();
        foreach ([[$this->realms->shop, new TargetAccounts($generator, false, $customers)], [$this->realms->backoffice, new TargetAccounts($generator, true, $staff)]] as [$realm, $target]) {
            $expected = array_map($target->id(...), $target->count > 0 ? range(1, $target->count) : []);
            sort($expected);
            self::assertSame($expected, $this->memberIds($realm), $realm);
            self::assertSame([], new AccountReconciler(pageSize: 2)->differences($this->realms->admin->realm($realm), $target), $realm);
        }
    }

    public function testTheGeneratedAccountsAreDisabledMembersWithoutCredentialsWithComputedIdsAndTheRolesOfTheirNumbers(): void
    {
        $this->succeeds(5, 12);

        $generator = $this->generator();
        $this->assertExactlyTheTarget(5, 12);
        $groups = $this->realms->json('GET', '/'.$this->realms->shop.'/groups', ['query' => ['search' => AccountReconciler::GROUP, 'exact' => 'true', 'briefRepresentation' => 'false']]);
        self::assertIsArray($groups[0]);
        self::assertSame(['description' => ['konta generatora danych demo; nie dodawaj do niej innych kont']], $groups[0]['attributes']);

        foreach ([[$this->realms->shop, range(1, 5), false], [$this->realms->backoffice, range(1, 12), true]] as [$realm, $numbers, $staff]) {
            foreach ($numbers as $number) {
                $account = $staff ? $generator->staff($number) : $generator->customer($number);
                $user = $this->realms->json('GET', '/'.$realm.'/users/'.$account->id);

                self::assertSame($account->username, $user['username']);
                self::assertSame($account->email, $user['email'] ?? null);
                self::assertSame([$account->firstName, $account->lastName], [$user['firstName'], $user['lastName']]);
                self::assertFalse($user['enabled']);
                self::assertSame([], $user['attributes'] ?? [], 'no attributes');
                self::assertSame([], $this->realms->json('GET', '/'.$realm.'/users/'.$account->id.'/credentials'), 'no credentials');
                $roles = array_values(array_intersect($this->realms->realmRoles($realm, $account->id), self::ROLES));
                self::assertSame($staff ? [$account->role] : [], $roles);
                if (!$staff) {
                    self::assertIsString($user['email']);
                    self::assertMatchesRegularExpression('/^[a-z-]+\.[a-z-]+\.'.$number.'@example\.(com|net|org)$/D', $user['email']);
                }
            }
        }
        self::assertSame('admin', $generator->staff(10)->role);
        self::assertSame('support', $generator->staff(11)->role);
    }

    public function testAGeneratedCustomerCanNeitherLogInNorAskForAResetOfThePassword(): void
    {
        $this->succeeds(1, 0);
        $customer = $this->generator()->customer(1);
        $email = (string) $customer->email;
        $inbox = new MailpitInbox($email);
        $inbox->clear();
        // An enabled account with an e-mail address and no password: the reset sends it a mail, so the check can see one.
        $control = 'control-'.bin2hex(random_bytes(5)).'@example.com';
        $this->realms->createUser($this->realms->shop, ['username' => $control, 'email' => $control, 'enabled' => true, 'firstName' => 'Kontrola', 'lastName' => 'Testu']);
        $controlInbox = new MailpitInbox($control);

        $http = HttpClient::create(['timeout' => 10]);
        $login = $http->request('POST', KeycloakTestRealms::url().'/realms/'.$this->realms->shop.'/protocol/openid-connect/token', [
            'body' => ['grant_type' => 'password', 'client_id' => KeycloakTestRealms::LOGIN_CLIENT, 'username' => $email, 'password' => 'any-password'],
        ]);
        self::assertGreaterThanOrEqual(400, $login->getStatusCode());
        self::assertArrayNotHasKey('access_token', $login->toArray(false));

        $this->requestAReset($email);
        $this->requestAReset($control);

        self::assertCount(1, $controlInbox->awaitMessages(1), 'The reset of an enabled account sends a mail.');
        self::assertSame(0, $inbox->count(), 'The reset of a generated account sends nothing.');
        self::assertSame([], $this->realms->json('GET', '/'.$this->realms->shop.'/users/'.$customer->id.'/sessions'));
    }

    public function testASecondRunWithTheSameOptionsChangesNothing(): void
    {
        $this->succeeds(4, 3);
        $before = $this->snapshots(4, 3);

        $display = $this->succeeds(4, 3)->getDisplay();

        self::assertStringContainsString($this->realms->backoffice.': 3 accounts of the target, 3 kept, 0 deleted, 0 imported, 0 conflicts', $display);
        self::assertStringContainsString($this->realms->shop.': 4 accounts of the target, 4 kept, 0 deleted, 0 imported, 0 conflicts', $display);
        self::assertSame($before, $this->snapshots(4, 3));
    }

    public function testAfterEveryRegenerationTheMembersAreExactlyTheTargetAndForeignAccountsAreUnchanged(): void
    {
        $foreign = $this->foreignAccounts();
        $other = Uuid::v4()->toRfc4122();

        // A count larger than a page of members (2), smaller and larger counts, another namespace.
        foreach ([[5, 3, null], [3, 2, null], [8, 4, null], [4, 2, $other]] as [$customers, $staff, $seed]) {
            $this->succeeds($customers, $staff, $seed);
            $this->assertExactlyTheTarget($customers, $staff, $this->generator($seed));
            $this->assertUnchanged($foreign);
        }

        // Other dictionaries: another fingerprint, so other ids for the same numbers.
        $copy = sys_get_temp_dir().'/accounts-step-'.bin2hex(random_bytes(6));
        mkdir($copy);
        try {
            foreach (['first-names.yaml', 'surnames.yaml', 'places.yaml'] as $file) {
                copy(self::FIXTURES.'/'.$file, $copy.'/'.$file);
            }
            $firstNames = (string) file_get_contents($copy.'/first-names.yaml');
            self::assertStringContainsString("  PIOTR: 682516\n", $firstNames);
            file_put_contents($copy.'/first-names.yaml', str_replace("  PIOTR: 682516\n", '', $firstNames));
            $generator = $this->generator($other, new PersonDictionaries($copy));
            $reconciler = new AccountReconciler(pageSize: 2, batchSize: 3, streams: 4);
            $shop = $reconciler->reconcile($this->realms->admin->realm($this->realms->shop), new TargetAccounts($generator, false, 4));
            $backoffice = $reconciler->reconcile($this->realms->admin->realm($this->realms->backoffice), new TargetAccounts($generator, true, 2));

            self::assertSame([4, 4, [], 2, 2, []], [$shop->deleted, $shop->imported, $shop->conflicts, $backoffice->deleted, $backoffice->imported, $backoffice->conflicts]);
            $this->assertExactlyTheTarget(4, 2, $generator);
            $this->assertUnchanged($foreign);
        } finally {
            array_map('unlink', glob($copy.'/*') ?: []);
            rmdir($copy);
        }
    }

    public function testAnInterruptedStepIsCompletedByTheNextRun(): void
    {
        $http = new class(HttpClient::create()) implements HttpClientInterface {
            use DecoratorTrait;

            private int $imports = 0;

            /**
             * @param array<string, mixed> $options
             */
            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                if (str_ends_with($url, '/partialImport') && 2 === ++$this->imports) {
                    throw new TransportException('The step was interrupted.');
                }

                return $this->client->request($method, $url, $options);
            }
        };
        $target = new TargetAccounts($this->generator(), false, 8);
        $reconciler = new AccountReconciler(pageSize: 2, batchSize: 3, streams: 1);
        try {
            $reconciler->reconcile(KeycloakTestRealms::admin($http)->realm($this->realms->shop), $target);
            self::fail('The step was not interrupted.');
        } catch (\RuntimeException) {
        }
        self::assertCount(3, $this->memberIds($this->realms->shop), 'The first batch was written.');

        $this->succeeds(8, 2);

        $this->assertExactlyTheTarget(8, 2);
    }

    public function testABatchRejectedInTheMiddleForAnIdCollisionFailsWithTheConflictAndTheNextRunCompletesTheTarget(): void
    {
        // An account of another realm with the id of customer 5: the second batch (customers 4 to 6) is rejected (409).
        $colliding = $this->generator()->customer(5);
        $this->realms->createUser($this->realms->backoffice, ['id' => $colliding->id, 'username' => 'someone.else', 'enabled' => true]);
        $foreign = $this->realms->snapshot($this->realms->backoffice, $colliding->id);

        $tester = $this->step(8, 0);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($colliding->id, $tester->getDisplay());
        self::assertStringContainsString('1 conflict(s)', $tester->getDisplay());
        $expected = array_map($this->generator()->customerId(...), [1, 2, 3, 4, 6, 7, 8]);
        sort($expected);
        self::assertSame($expected, $this->memberIds($this->realms->shop));
        self::assertSame($foreign, $this->realms->snapshot($this->realms->backoffice, $colliding->id));

        $this->realms->json('DELETE', '/'.$this->realms->backoffice.'/users/'.$colliding->id);
        $this->succeeds(8, 0);
        $this->assertExactlyTheTarget(8, 0);
    }

    public function testAUsernameOrAnEmailTakenOutsideTheGroupFailsTheStepWithTheConflictsAndChangesNoForeignAccount(): void
    {
        $generator = $this->generator();
        // The realm takes the e-mail address as the username of an account made through the users endpoint, so the two
        // accounts with a username different from the e-mail address come from an import.
        $byUsername = $this->realms->createUser($this->realms->shop, ['id' => Uuid::v4()->toRfc4122(), 'username' => $generator->customer(2)->username, 'email' => 'owner-'.bin2hex(random_bytes(4)).'@example.com', 'enabled' => true]);
        $byEmail = $this->realms->createUser($this->realms->shop, ['id' => Uuid::v4()->toRfc4122(), 'username' => 'other-'.bin2hex(random_bytes(4)), 'email' => $generator->customer(4)->email, 'enabled' => true]);
        $staffName = $this->realms->createUser($this->realms->backoffice, ['username' => $generator->staff(1)->username, 'enabled' => true], ['admin']);
        $foreign = [[$this->realms->shop, $byUsername], [$this->realms->shop, $byEmail], [$this->realms->backoffice, $staffName]];
        $snapshots = array_map(fn (array $account): array => $this->realms->snapshot(...$account), $foreign);

        $tester = $this->step(6, 2);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('3 conflict(s)', $display);
        self::assertStringContainsString($generator->customer(2)->username.' (id '.$generator->customerId(2).'): the username is taken by the account '.$byUsername, $display);
        self::assertStringContainsString('the e-mail address '.(string) $generator->customer(4)->email.' is taken by the account', $display);
        self::assertStringContainsString($generator->staff(1)->username.' (id '.$generator->staffId(1).'): the username is taken by the account '.$staffName, $display);
        self::assertSame($snapshots, array_map(fn (array $account): array => $this->realms->snapshot(...$account), $foreign));
        $expected = array_map($generator->customerId(...), [1, 3, 5, 6]);
        sort($expected);
        self::assertSame($expected, $this->memberIds($this->realms->shop));
    }

    public function testWithoutTheGroupNothingIsDeleted(): void
    {
        $this->succeeds(5, 2);
        $groups = $this->realms->json('GET', '/'.$this->realms->shop.'/groups', ['query' => ['search' => AccountReconciler::GROUP, 'exact' => 'true']]);
        self::assertIsArray($groups[0]);
        self::assertIsString($groups[0]['id']);
        $this->realms->json('DELETE', '/'.$this->realms->shop.'/groups/'.$groups[0]['id']);
        $accounts = array_map($this->generator()->customerId(...), range(1, 5));
        $before = array_map(fn (string $id): array => $this->realms->snapshot($this->realms->shop, $id), $accounts);

        $tester = $this->step(3, 2);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('3 conflict(s)', $tester->getDisplay());
        self::assertStringContainsString('0 deleted', $tester->getDisplay());
        self::assertSame($before, array_map(fn (string $id): array => $this->realms->snapshot($this->realms->shop, $id), $accounts));
        self::assertSame([], $this->memberIds($this->realms->shop), 'The group is created anew, empty.');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusals(): iterable
    {
        yield 'Keycloak does not answer' => [['--keycloak-url' => 'http://127.0.0.1:9'], 'Keycloak at http://127.0.0.1:9 does not answer'];
        yield 'a wrong password' => [['--keycloak-password' => 'not-the-password'], 'refused the login of the administrator admin (HTTP 4'];
        yield 'an empty password' => [['--keycloak-password' => ''], 'No password of the Keycloak administrator'];
        yield 'no batch' => [['--batch-size' => 0], '--batch-size and --streams have to be at least 1'];
        yield 'one realm for both' => [['--backoffice-realm' => 'same', '--shop-realm' => 'same'], 'two different names'];
        yield 'negative count' => [['--customers' => -1], 'cannot be negative'];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testTheStepFailsWithoutChangesOnABadOptionOrWithoutKeycloak(array $options, string $message): void
    {
        $tester = $this->step(3, 2, null, $options);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($message, (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertStringNotContainsString('not-the-password', $tester->getDisplay());
        self::assertSame([], $this->memberIds($this->realms->shop));
        self::assertSame([], $this->memberIds($this->realms->backoffice));
    }

    /**
     * The accounts outside the group of the threat model: one with a username in the format of the generator and the id
     * the seed gives customer 9, an ordinary one and one created like create-admin (role admin, e-mail, no password).
     *
     * @return list<array{string, string, array<string, mixed>}> realm, id and snapshot
     */
    private function foreignAccounts(): array
    {
        $lookalike = $this->generator()->customer(9);
        $accounts = [
            [$this->realms->shop, $this->realms->createUser($this->realms->shop, ['id' => $lookalike->id, 'username' => $lookalike->username, 'email' => $lookalike->email, 'enabled' => true])],
            [$this->realms->shop, $this->realms->createUser($this->realms->shop, ['username' => 'jan.kowalski@example.com', 'email' => 'jan.kowalski@example.com', 'enabled' => true, 'firstName' => 'Jan', 'lastName' => 'Kowalski'])],
            [$this->realms->backoffice, $this->realms->createUser($this->realms->backoffice, ['username' => 'szef', 'email' => 'szef@example.com', 'enabled' => true], ['admin'])],
        ];

        return array_map(fn (array $account): array => [$account[0], $account[1], $this->realms->snapshot($account[0], $account[1])], $accounts);
    }

    /**
     * @param list<array{string, string, array<string, mixed>}> $foreign
     */
    private function assertUnchanged(array $foreign): void
    {
        foreach ($foreign as [$realm, $id, $snapshot]) {
            self::assertSame($snapshot, $this->realms->snapshot($realm, $id), $id);
        }
    }

    /**
     * @return array<string, mixed> the snapshot of every generated account by its id
     */
    private function snapshots(int $customers, int $staff): array
    {
        $generator = $this->generator();
        $snapshots = [];
        foreach (range(1, $customers) as $number) {
            $snapshots[$generator->customerId($number)] = $this->realms->snapshot($this->realms->shop, $generator->customerId($number));
        }
        foreach (range(1, $staff) as $number) {
            $snapshots[$generator->staffId($number)] = $this->realms->snapshot($this->realms->backoffice, $generator->staffId($number));
        }

        return $snapshots;
    }

    /**
     * Asks for a reset of the password like the form of the login page: the page of the realm with its cookies, then the
     * form with the username. The links of the page carry the public URL of Keycloak, so the form goes to the internal one.
     */
    private function requestAReset(string $username): void
    {
        $http = HttpClient::create(['timeout' => 10, 'max_redirects' => 0]);
        $page = $http->request('GET', KeycloakTestRealms::url().'/realms/'.$this->realms->shop.'/login-actions/reset-credentials', ['query' => ['client_id' => KeycloakTestRealms::LOGIN_CLIENT]]);
        self::assertSame(200, $page->getStatusCode());
        $cookies = array_map(static fn (string $cookie): string => explode(';', $cookie)[0], $page->getHeaders()['set-cookie'] ?? []);
        self::assertSame(1, preg_match('/<form[^>]*id="kc-reset-password-form"[^>]*action="([^"]+)"/', $page->getContent(), $form));
        $action = (string) preg_replace('#^https?://[^/]+#', KeycloakTestRealms::url(), html_entity_decode($form[1]));

        $sent = $http->request('POST', $action, ['headers' => ['Cookie' => implode('; ', $cookies)], 'body' => ['username' => $username]]);

        self::assertContains($sent->getStatusCode(), [200, 302], $sent->getContent(false));
    }
}
