<?php

declare(strict_types=1);

namespace App\DemoData\Console;

use App\DemoData\Accounts\AccountDirectory;
use App\DemoData\Accounts\AccountGenerator;
use App\DemoData\Accounts\KeycloakAdminFactory;
use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Accounts\TargetAccounts;
use App\DemoData\Profile\RunContext;
use Symfony\Component\Console\Attribute\Option;

/**
 * The options of the accounts in Keycloak, shared by demo-data:accounts and the account checks of demo-data:verify.
 */
final class AccountsInput
{
    #[Option(description: 'URL of Keycloak (default: DEMO_DATA_KEYCLOAK_URL or http://keycloak:8080)')]
    public ?string $keycloakUrl = null;

    #[Option(description: 'Login of the Keycloak administrator (default: admin)')]
    public ?string $keycloakUser = null;

    #[Option(description: 'Password of the Keycloak administrator (default: KC_BOOTSTRAP_ADMIN_PASSWORD)')]
    public ?string $keycloakPassword = null;

    #[Option(description: 'Realm of the customers')]
    public string $shopRealm = 'shop';

    #[Option(description: 'Realm of the staff')]
    public string $backofficeRealm = 'backoffice';

    #[Option(description: 'Number of staff accounts instead of the one of the profile')]
    public ?int $staff = null;

    #[Option(description: 'Number of customer accounts instead of the one of the profile')]
    public ?int $customers = null;

    #[Option(description: 'Members read per request')]
    public int $pageSize = 1000;

    /**
     * The realm of the staff and the realm of the customers, each with its directory and its target set.
     *
     * @return list<array{string, AccountDirectory, TargetAccounts}>
     *
     * @throws \InvalidArgumentException for a bad option or a missing password
     */
    public function realms(RunContext $context, PersonDictionaries $people, KeycloakAdminFactory $keycloak): array
    {
        $staff = $this->staff ?? $context->profile->staff;
        $customers = $this->customers ?? $context->profile->customers;
        if ($staff < 0 || $customers < 0 || $this->pageSize < 1) {
            throw new \InvalidArgumentException('--staff and --customers cannot be negative, and --page-size has to be at least 1.');
        }
        if ('' === $this->shopRealm || '' === $this->backofficeRealm || $this->shopRealm === $this->backofficeRealm) {
            throw new \InvalidArgumentException('--shop-realm and --backoffice-realm have to be two different names.');
        }
        $admin = $keycloak->connect($this->keycloakUrl, $this->keycloakUser, $this->keycloakPassword);
        $generator = new AccountGenerator($context->namespace, $people);

        return [
            [$this->backofficeRealm, $admin->realm($this->backofficeRealm), new TargetAccounts($generator, true, $staff)],
            [$this->shopRealm, $admin->realm($this->shopRealm), new TargetAccounts($generator, false, $customers)],
        ];
    }
}
