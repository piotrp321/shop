<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Accounts\AccountGenerator;
use App\DemoData\Accounts\AccountReconciler;
use App\DemoData\Accounts\PersonDictionaries;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Uid\Uuid;

/**
 * The account checks of demo-data:verify: the members of the group of each realm are the target set. The generated
 * data of the database and the accounts come from one seed unique to the test, the accounts go to temporary realms.
 */
final class AccountsVerifyTest extends KernelTestCase
{
    use RunsDemoDataSteps;

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
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function accountOptions(): array
    {
        return [
            '--seed' => $this->seed,
            '--customers' => 4,
            '--staff' => 3,
            '--shop-realm' => $this->realms->shop,
            '--backoffice-realm' => $this->realms->backoffice,
            '--page-size' => 2,
        ];
    }

    public function testVerifyPassesWhenTheMembersAreTheTargetSetsAndListsEveryDifference(): void
    {
        foreach (['demo-data:categories', 'demo-data:products', 'demo-data:photos', 'demo-data:prices', 'demo-data:promotions'] as $step) {
            $this->succeeds($step, ['--seed' => $this->seed]);
        }
        $this->succeeds('demo-data:analyze', [], false);

        $missing = $this->step('demo-data:verify', $this->accountOptions());
        self::assertSame(Command::FAILURE, $missing->getStatusCode());
        self::assertStringContainsString($this->realms->shop.': the group '.AccountReconciler::GROUP.' does not exist', $missing->getDisplay());

        $this->succeeds('demo-data:accounts', $this->accountOptions() + ['--batch-size' => 3]);
        $this->succeeds('demo-data:verify', $this->accountOptions());

        // A generated customer leaves the group, a foreign account joins it.
        $groupId = $this->realms->admin->realm($this->realms->shop)->findGroup(AccountReconciler::GROUP);
        self::assertNotNull($groupId);
        $customer = new AccountGenerator($this->seed, new PersonDictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data'))->customerId(2);
        $this->realms->json('DELETE', '/'.$this->realms->shop.'/users/'.$customer.'/groups/'.$groupId);
        $foreign = $this->realms->createUser($this->realms->shop, ['username' => 'obcy@example.com', 'email' => 'obcy@example.com', 'enabled' => true]);
        $this->realms->json('PUT', '/'.$this->realms->shop.'/users/'.$foreign.'/groups/'.$groupId);

        $tester = $this->step('demo-data:verify', $this->accountOptions());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('2 violation(s)', $display);
        self::assertStringContainsString($this->realms->shop.': 1 member(s) of the group '.AccountReconciler::GROUP.' outside the target set or not current, e.g. '.$foreign, $display);
        self::assertStringContainsString($this->realms->shop.': 1 account(s) of the target set missing from the group '.AccountReconciler::GROUP.' or not current, e.g. numbers 2', $display);
    }

    public function testVerifyWithoutTheAccountsChecksOnlyTheDatabase(): void
    {
        $tester = $this->step('demo-data:verify', ['--no-accounts' => true, '--keycloak-url' => 'http://127.0.0.1:9']);

        self::assertStringNotContainsString(AccountReconciler::GROUP, $tester->getDisplay());
        self::assertStringNotContainsString('Keycloak', $tester->getDisplay());
    }

    public function testVerifyFailsWhenKeycloakDoesNotAnswer(): void
    {
        $tester = $this->step('demo-data:verify', $this->accountOptions() + ['--keycloak-url' => 'http://127.0.0.1:9']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Keycloak at http://127.0.0.1:9 does not answer', $tester->getDisplay());
    }
}
