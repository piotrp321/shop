<?php

declare(strict_types=1);

namespace App\Pricing\UI\Console;

use App\Pricing\Application\TaxReconciliation\ReconcileTax;
use App\Pricing\Application\TaxReconciliation\TaxDiscrepancy;
use App\Pricing\Application\TaxReconciliation\TaxDiscrepancyOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists every price whose VAT rate or tax class is not the one it should have, one line each as it is dealt with;
 * with --apply reprices them. Run deliberately (e.g. after a rate changed in PolishVatRates), never by make init,
 * make up or make seed.
 *
 * Exit codes: 0 every price is consistent or was fixed, 1 discrepancies remain (found without --apply, or not fixed
 * with --apply: changed concurrently twice, or a class without a rate), 2 the run stopped on an error (e.g. the
 * database is unavailable); the prices fixed until then keep their change.
 */
#[AsCommand(name: 'pricing:reconcile-tax', description: 'Finds prices with an outdated VAT rate or a tax class other than in Catalog; --apply reprices them.')]
final readonly class ReconcileTaxCommand
{
    public const int DISCREPANCIES = 1;
    public const int ERROR = 2;

    public function __construct(private ReconcileTax $reconcile)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Reprice the prices found (the same gross, the class from Catalog and its current rate)')]
        bool $apply = false,
    ): int {
        $found = 0;
        $fixed = 0;
        try {
            $run = ($this->reconcile)($apply);
            foreach ($run as $discrepancy) {
                ++$found;
                if ($discrepancy->isFixed()) {
                    ++$fixed;
                }
                $io->writeln($this->line($discrepancy));
            }
            $checked = $run->getReturn();
        } catch (\Throwable $e) {
            $io->error(\sprintf('The reconciliation stopped: %s The prices fixed so far keep their change; run the command again.', rtrim($e->getMessage(), '.').'.'));

            return self::ERROR;
        }

        if (0 === $found) {
            $io->success(\sprintf('Checked %d prices: all consistent.', $checked));

            return Command::SUCCESS;
        }
        if (!$apply) {
            $io->warning(\sprintf('Checked %d prices: %d discrepancies, nothing was changed. Run with --apply to fix them.', $checked, $found));

            return self::DISCREPANCIES;
        }
        if ($fixed === $found) {
            $io->success(\sprintf('Checked %d prices: %d fixed.', $checked, $fixed));

            return Command::SUCCESS;
        }
        $io->error(\sprintf('Checked %d prices: %d fixed, %d not fixed.', $checked, $fixed, $found - $fixed));

        return self::DISCREPANCIES;
    }

    private function line(TaxDiscrepancy $discrepancy): string
    {
        return \sprintf(
            '%s: %s %d bp -> %s %s: %s',
            $discrepancy->variantId->toString(),
            $discrepancy->storedClass->toString(),
            $discrepancy->storedRate->basisPoints(),
            $discrepancy->expectedClass->toString(),
            null === $discrepancy->expectedRate ? '(no rate)' : $discrepancy->expectedRate->basisPoints().' bp',
            match ($discrepancy->outcome) {
                TaxDiscrepancyOutcome::Found => 'found',
                TaxDiscrepancyOutcome::Fixed => 'fixed',
                TaxDiscrepancyOutcome::ModifiedConcurrently => 'not fixed, the price was changed concurrently; run the command again',
                TaxDiscrepancyOutcome::NoRate => 'not fixed, the class has no VAT rate',
            },
        );
    }
}
