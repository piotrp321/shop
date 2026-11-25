<?php

declare(strict_types=1);

namespace App\DemoData\Console;

use App\DemoData\Accounts\AccountReconciler;
use App\DemoData\Accounts\KeycloakAdminFactory;
use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Profile\RunContextFactory;
use App\DemoData\Verify\CatalogVerifier;
use App\DemoData\Verify\OrdersVerifier;
use App\DemoData\Verify\PricingVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The consistency check after the load (the last step of bin/generate-demo, also on its own). The catalog checks: a
 * published product without a variant, the counts of the tables against the profile, the slugs of the tail (unique,
 * different from the slugs of fixtures/catalog.yaml), a generated product without a brand, a description or parameters
 * and a generated variant without a SKU or an EAN (the seed's own products and variants are left out). The price checks: a variant without a price or history, a price
 * that is not the rule of Pricing, the promotions (ended, at most 1000 variants, no overlap) and the discount codes.
 * The order and stock checks: the number of orders, a variant without a stock record, reserved stock that is not the
 * sum of the active reservations, a last movement that is not the stock, a paid order without an active reservation or
 * a payment of its total, lines less the discount that are not the total, an order with a code without its usage, a
 * customer outside the generated accounts, a history that does not end with the status, a line price that is not the
 * effective price at the placement (a sample of the orders in the large profiles; `--orders` overrides the count).
 * The account checks (Keycloak, the options of demo-data:accounts; `--no-accounts` skips them): in each realm the group
 * demo-data-generated exists and its members are exactly the target set with current data.
 * Exit codes: 0 no violation, 1 violations (listed), a bad option or Keycloak not answering.
 */
#[AsCommand(name: 'demo-data:verify', description: 'Checks that the generated data is consistent; lists the violations.')]
final readonly class VerifyCommand
{
    public function __construct(
        private RunContextFactory $contexts,
        private CatalogVerifier $catalog,
        private PricingVerifier $pricing,
        private OrdersVerifier $orders,
        private PersonDictionaries $people,
        private KeycloakAdminFactory $keycloak,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[MapInput]
        AccountsInput $keycloak,
        #[Option(description: 'Profile of profiles.yaml (not --profile, which the console itself reserves)')]
        string $demoProfile = 'small',
        #[Option(description: 'Reference moment (default: the start of the current UTC day)')]
        ?string $anchor = null,
        #[Option(description: 'Namespace of the seed (a UUID) instead of the one of profiles.yaml')]
        ?string $seed = null,
        #[Option(description: 'Number of products instead of the one of the profile')]
        ?int $products = null,
        #[Option(description: 'Check the accounts in Keycloak (--no-accounts: only the database)')]
        bool $accounts = true,
        #[Option(description: 'Number of orders instead of the one of the profile')]
        ?int $orders = null,
    ): int {
        try {
            $context = $this->contexts->create($demoProfile, $seed, $products, $anchor, $orders);
            $realms = $accounts ? $keycloak->realms($context, $this->people, $this->keycloak) : [];
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $violations = [...$this->catalog->violations($context), ...$this->pricing->violations($context), ...$this->orders->violations($context)];
        $reconciler = new AccountReconciler(max(1, $keycloak->pageSize));
        foreach ($realms as [$realm, $directory, $target]) {
            try {
                foreach ($reconciler->differences($directory, $target) as $difference) {
                    $violations[] = $realm.': '.$difference;
                }
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $io->error(\sprintf('%s: %s', $realm, $e->getMessage()));

                return Command::FAILURE;
            }
        }
        if ([] !== $violations) {
            $io->error(\sprintf('%d violation(s):', \count($violations)));
            $io->listing($violations);

            return Command::FAILURE;
        }
        $io->success('the generated data is consistent');

        return Command::SUCCESS;
    }
}
