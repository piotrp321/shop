<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Console;

use App\Pricing\Application\TaxReconciliation\ReconcileTax;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\ProductId;
use App\Pricing\Domain\Price\ProductPrice;
use App\Pricing\Domain\Price\ProductPriceRepository;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Domain\Tax\VatRate;
use App\Pricing\UI\Console\ReconcileTaxCommand;
use App\Tests\Unit\Pricing\ConcurrentlyEditedProductPriceRepository;
use App\Tests\Unit\Pricing\FixedVatRates;
use App\Tests\Unit\Pricing\InMemoryPersistenceSession;
use App\Tests\Unit\Pricing\InMemoryProductPriceRepository;
use App\Tests\Unit\Pricing\InMemoryProductsForPricing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ReconcileTaxCommandTest extends TestCase
{
    private const string FIRST = '00000000-0000-4000-8000-000000000001';
    private const string SECOND = '00000000-0000-4000-8000-000000000002';

    private InMemoryProductPriceRepository $prices;
    private InMemoryPersistenceSession $session;
    private ConcurrentlyEditedProductPriceRepository $repository;
    private FixedVatRates $rates;

    protected function setUp(): void
    {
        $this->rates = new FixedVatRates(['standard' => 2300, 'printed_book' => 500, 'energy_gel' => 800]);
        $this->prices = new InMemoryProductPriceRepository();
        $this->session = new InMemoryPersistenceSession();
        $this->repository = new ConcurrentlyEditedProductPriceRepository($this->prices, $this->session, $this->rates);
    }

    public function testConsistentPricesExitWithZero(): void
    {
        $this->storePrice(self::FIRST, 'standard', 2300);
        $this->storePrice(self::SECOND, 'printed_book', 500);
        $tester = $this->tester([self::SECOND => 'printed_book']);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('Checked 2 prices: all consistent.', $this->display($tester));
    }

    public function testDiscrepanciesWithoutApplyAreListedAndExitWithOne(): void
    {
        $this->storePrice(self::FIRST, 'energy_gel', 500);
        $this->storePrice(self::SECOND, 'standard', 2300);
        $tester = $this->tester([self::FIRST => 'energy_gel', self::SECOND => 'printed_book']);

        self::assertSame(1, $tester->execute([]), $tester->getDisplay());

        $display = $this->display($tester);
        self::assertStringContainsString(self::FIRST.': energy_gel 500 bp -> energy_gel 800 bp: found', $display);
        self::assertStringContainsString(self::SECOND.': standard 2300 bp -> printed_book 500 bp: found', $display);
        self::assertStringContainsString('Checked 2 prices: 2 discrepancies, nothing was changed. Run with --apply to fix them.', $display);
        self::assertSame(0, $this->prices->writes);
    }

    public function testApplyFixesTheDiscrepanciesExitsWithZeroAndTheNextRunFindsNothing(): void
    {
        $this->storePrice(self::FIRST, 'energy_gel', 500);
        $this->storePrice(self::SECOND, 'standard', 2300);
        $tester = $this->tester([self::FIRST => 'energy_gel', self::SECOND => 'printed_book']);

        self::assertSame(0, $tester->execute(['--apply' => true]), $tester->getDisplay());

        $display = $this->display($tester);
        self::assertStringContainsString(self::FIRST.': energy_gel 500 bp -> energy_gel 800 bp: fixed', $display);
        self::assertStringContainsString(self::SECOND.': standard 2300 bp -> printed_book 500 bp: fixed', $display);
        self::assertStringContainsString('Checked 2 prices: 2 fixed.', $display);
        self::assertSame(2, $this->prices->writes);

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('Checked 2 prices: all consistent.', $this->display($tester));
    }

    /**
     * A discrepancy --apply could not fix is still a discrepancy: not 0, and not 2 either, the run went through.
     */
    public function testPricesApplyCouldNotFixAreListedAndExitWithOne(): void
    {
        $this->storePrice(self::FIRST, 'standard', 2300);
        $this->storePrice(self::SECOND, 'standard', 2300);
        $this->repository->editBeforeSave(self::FIRST, 2);
        $tester = $this->tester([self::FIRST => 'printed_book', self::SECOND => 'retired_class']);

        self::assertSame(1, $tester->execute(['--apply' => true]), $tester->getDisplay());

        $display = $this->display($tester);
        self::assertStringContainsString(self::FIRST.': standard 2300 bp -> printed_book 500 bp: not fixed, the price was changed concurrently; run the command again', $display);
        self::assertStringContainsString(self::SECOND.': standard 2300 bp -> retired_class (no rate): not fixed, the class has no VAT rate', $display);
        self::assertStringContainsString('Checked 2 prices: 0 fixed, 2 not fixed.', $display);
    }

    public function testFailureStopsTheRunAndExitsWithTwo(): void
    {
        $this->storePrice(self::FIRST, 'energy_gel', 500);
        $failing = new class($this->repository) implements ProductPriceRepository {
            public function __construct(private readonly ProductPriceRepository $inner)
            {
            }

            public function find(ProductId $id): ?ProductPrice
            {
                return $this->inner->find($id);
            }

            public function findMany(array $ids): array
            {
                return $this->inner->findMany($ids);
            }

            public function page(?ProductId $after, int $limit): array
            {
                return $this->inner->page($after, $limit);
            }

            public function save(ProductPrice $price): void
            {
                throw new \RuntimeException('connection to server at "postgres" failed');
            }
        };
        $tester = $this->tester([self::FIRST => 'energy_gel'], $failing);

        self::assertSame(2, $tester->execute(['--apply' => true]), $tester->getDisplay());

        $display = $this->display($tester);
        self::assertStringContainsString('The reconciliation stopped: connection to server at "postgres" failed', $display);
        self::assertStringContainsString('The prices fixed so far keep their change; run the command again.', $display);
    }

    /**
     * @param array<string, string> $catalog product id => tax class code
     */
    private function tester(array $catalog, ?ProductPriceRepository $prices = null): CommandTester
    {
        $products = new InMemoryProductsForPricing(array_map(static fn (string $class): string => 'slug', $catalog), $catalog);
        $application = new Application();
        $application->addCommand(new ReconcileTaxCommand(new ReconcileTax($prices ?? $this->repository, $products, $this->rates, $this->session)));

        return new CommandTester($application->find('pricing:reconcile-tax'));
    }

    private function display(CommandTester $tester): string
    {
        return preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
    }

    private function storePrice(string $id, string $taxClass, int $basisPoints): void
    {
        $this->prices->store(ProductPrice::reconstitute(
            ProductId::fromString($id),
            PriceBreakdown::fromGross(GrossPrice::pln(5490), VatRate::fromBasisPoints($basisPoints)),
            TaxClass::fromString($taxClass),
            1,
            new \DateTimeImmutable('2026-01-01'),
        ));
    }
}
