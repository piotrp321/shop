<?php

declare(strict_types=1);

namespace App\Tests\Integration\DemoData;

use App\DemoData\Accounts\AccountGenerator;
use App\DemoData\Accounts\PersonDictionaries;
use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Identity\StaffRoles;
use App\DemoData\Pricing\DiscountCodePlan;
use App\DemoData\Pricing\PriceGenerator;
use App\DemoData\Pricing\PromotionPlanner;
use App\DemoData\Pricing\PromotionSpec;
use App\DemoData\Profile\Profiles;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeStatus;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Price\PriceBreakdown;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\PromotionId;
use App\Pricing\Domain\Promotion\PromotionStatus;
use App\Pricing\Domain\Tax\PolishVatRates;
use App\Pricing\Domain\Tax\TaxClass;
use App\Pricing\Infrastructure\Persistence\Doctrine\DbalPriceHistoryReader;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineDiscountCodeRepository;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrinePromotionRepository;
use App\Pricing\Infrastructure\Persistence\Doctrine\DoctrineVariantPriceRepository;
use App\Tests\Support\InMemory\RecordingDomainEventPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;

/**
 * The price steps of the generator with the profile small, on shop_test in the transaction of the test: a price with
 * history for every variant, the ended promotions and the historical discount codes, all read back through the
 * repositories of Pricing.
 */
final class PricingStepsTest extends KernelTestCase
{
    use RunsDemoDataSteps;

    private const string NAMESPACE = '8d3b6f1e-5a27-4c90-b1e4-7f02a96c35d8';

    private static function anchor(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::ANCHOR);
    }

    protected function setUp(): void
    {
        foreach (['catalog_product', 'pricing_variant_price', 'pricing_price_history', 'pricing_promotion', 'pricing_discount_code'] as $table) {
            self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM '.$table)), 'shop_test has rows in '.$table.' that are not the ones of this test.');
        }
    }

    public function testThePriceStepsOfTheSmallProfileEndWithCodeZeroAndFillTheTables(): void
    {
        $this->catalogSteps();

        $this->pricingSteps();

        $connection = $this->connection();
        $variants = $this->int($connection->fetchOne('SELECT count(*) FROM catalog_product_variant'));
        self::assertGreaterThan(5_000, $variants);
        self::assertSame($variants, $this->int($connection->fetchOne('SELECT count(*) FROM pricing_variant_price')));
        self::assertGreaterThanOrEqual($variants, $this->int($connection->fetchOne('SELECT count(*) FROM pricing_price_history')));
        self::assertGreaterThanOrEqual(30, $this->int($connection->fetchOne('SELECT count(*) FROM pricing_promotion')));
        self::assertGreaterThan(0, $this->int($connection->fetchOne('SELECT count(*) FROM pricing_promotion_variant')));
        self::assertGreaterThanOrEqual(12, $this->int($connection->fetchOne('SELECT count(*) FROM pricing_discount_code')));
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM pricing_discount_code_usage')), 'The orders make the usages.');
    }

    public function testEveryVariantHasExactlyOneCurrentPriceAndAHistoryThatEndsWithIt(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();
        $connection = $this->connection();

        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM catalog_product_variant v WHERE NOT EXISTS (SELECT 1 FROM pricing_variant_price p WHERE p.variant_id = v.id)')));
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM catalog_product_variant v WHERE NOT EXISTS (SELECT 1 FROM pricing_price_history h WHERE h.variant_id = v.id)')));
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM pricing_variant_price p WHERE NOT EXISTS (SELECT 1 FROM catalog_product_variant v WHERE v.id = p.variant_id)')));
        self::assertSame(0, $this->int($connection->fetchOne(
            'SELECT count(*) FROM pricing_variant_price p JOIN LATERAL (SELECT * FROM pricing_price_history h WHERE h.variant_id = p.variant_id ORDER BY h.changed_at DESC, h.id DESC LIMIT 1) l ON true
             WHERE (l.gross_amount, l.net_amount, l.vat_amount, l.vat_rate_bp, l.tax_class, l.currency, l.changed_at) IS DISTINCT FROM (p.gross_amount, p.net_amount, p.vat_amount, p.vat_rate_bp, p.tax_class, p.currency, p.updated_at)',
        )));
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM pricing_variant_price p WHERE p.version <> (SELECT count(*) FROM pricing_price_history h WHERE h.variant_id = p.variant_id)')), 'The version counts the writes: the first price and one change.');
    }

    public function testTheRowsAreTheOnesTheGeneratorComputes(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();
        $profiles = Profiles::fromFile(\dirname(__DIR__, 3).'/fixtures/demo-data/profiles.yaml');
        $dictionaries = new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data');
        $products = new ProductGenerator($dictionaries, $profiles->namespace, self::anchor(), 2_000, $profiles->partSize);
        $prices = new PriceGenerator($dictionaries, $profiles->namespace, self::anchor(), 20, new PersonDictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data'));
        $byVariant = PromotionPlanner::byVariant(new PromotionPlanner($dictionaries, $products, $profiles->namespace, self::anchor(), 2_000, $profiles->partSize)->plan());

        $checked = 0;
        foreach ($products->part(2) as $product) {
            foreach ($prices->forProduct($product, $byVariant) as $position => $timeline) {
                $variantId = $product->variants[$position]->id;
                $rows = $this->connection()->fetchAllAssociative('SELECT id, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, source, actor_id, actor_name, extract(epoch FROM changed_at)::int AS at FROM pricing_price_history WHERE variant_id = ? ORDER BY changed_at, id', [$variantId]);
                self::assertCount(\count($timeline->entries()), $rows);
                foreach ($timeline->entries() as $index => $entry) {
                    self::assertSame($entry->id, $rows[$index]['id']);
                    self::assertSame($entry->gross, $this->int($rows[$index]['gross_amount']));
                    self::assertSame($entry->net, $this->int($rows[$index]['net_amount']));
                    self::assertSame($entry->vat, $this->int($rows[$index]['vat_amount']));
                    self::assertSame($entry->source, $rows[$index]['source']);
                    self::assertSame($entry->actorId, $rows[$index]['actor_id']);
                    self::assertSame($entry->actorName, $rows[$index]['actor_name']);
                    self::assertSame($entry->changedAt->getTimestamp(), $this->int($rows[$index]['at']));
                }
                ++$checked;
            }
        }
        self::assertGreaterThan(1_000, $checked);
    }

    public function testPricesReconstituteThroughTheRepositoryAndBreakDownByThePricingRule(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $repository = new DoctrineVariantPriceRepository($entityManager, new RecordingDomainEventPublisher());
        $rates = new PolishVatRates();

        $ids = array_map($this->text(...), $this->connection()->fetchFirstColumn('SELECT variant_id FROM pricing_variant_price ORDER BY variant_id'));
        $found = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($repository->findMany(array_map(VariantId::fromString(...), $chunk)) as $price) {
                $expected = PriceBreakdown::fromGross(GrossPrice::pln($price->breakdown()->gross()->amount()), $rates->rateFor(TaxClass::fromString($price->taxClass()->toString())));
                self::assertTrue($expected->equals($price->breakdown()), $price->id()->toString());
                self::assertTrue($price->isCurrent($rates), $price->id()->toString());
                ++$found;
            }
        }
        self::assertSame(\count($ids), $found);

        $history = new DbalPriceHistoryReader($this->connection())->page(VariantId::fromString($ids[0]), null, 10);
        self::assertNotSame([], $history);
    }

    public function testAboutThirtyPercentOfTheVariantsChangedTheirPriceInTheLastNinetyDaysByAnAdmin(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();
        $connection = $this->connection();

        $variants = $this->int($connection->fetchOne('SELECT count(*) FROM pricing_variant_price'));
        $changed = $this->int($connection->fetchOne("SELECT count(DISTINCT variant_id) FROM pricing_price_history WHERE source = 'staff'"));
        self::assertEqualsWithDelta(0.30, $changed / $variants, 0.04);

        // The actors are the generated staff accounts with the role admin, by their ids and names.
        $accounts = new AccountGenerator(self::NAMESPACE, new PersonDictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data'));
        $admins = array_map(static fn (int $number): string => $accounts->staffId($number).' '.$accounts->staff($number)->person->fullName(), StaffRoles::adminNumbers(20));
        $actors = array_map($this->text(...), $connection->fetchFirstColumn("SELECT DISTINCT actor_id || ' ' || actor_name FROM pricing_price_history WHERE source = 'staff'"));
        self::assertNotSame([], $actors);
        self::assertSame([], array_diff($actors, $admins));
        self::assertSame(0, $this->int($connection->fetchOne("SELECT count(*) FROM pricing_price_history WHERE source = 'seed' AND actor_id IS NOT NULL")));
        self::assertSame(0, $this->int($connection->fetchOne("SELECT count(*) FROM pricing_price_history WHERE source = 'staff' AND (changed_at < ?::timestamptz - interval '90 days' OR changed_at >= ?::timestamptz)", [self::ANCHOR, self::ANCHOR])));
    }

    public function testNoPriceEntryIsAfterTheFirstPossibleOrder(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();

        // The first entry of every published variant is before the product is published: the first order of a variant is placed after that.
        self::assertSame(0, $this->int($this->connection()->fetchOne(
            "SELECT count(*) FROM catalog_product_variant v JOIN catalog_product p ON p.id = v.product_id WHERE p.status = 'published' AND (SELECT min(h.changed_at) FROM pricing_price_history h WHERE h.variant_id = v.id) >= p.first_published_at",
        )));
    }

    public function testThePromotionsAreEndedWithAtMostAThousandVariantsAndNeverOverlapOnAVariant(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();
        $connection = $this->connection();

        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM pricing_promotion WHERE ends_at > ?::timestamptz', [self::ANCHOR])));
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM (SELECT promotion_id FROM pricing_promotion_variant GROUP BY promotion_id HAVING count(*) > 1000) t')));
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM pricing_promotion p WHERE NOT EXISTS (SELECT 1 FROM pricing_promotion_variant v WHERE v.promotion_id = p.id)')));
        self::assertSame(0, $this->int($connection->fetchOne(
            'SELECT count(*) FROM pricing_promotion_variant a JOIN pricing_promotion_variant b ON b.variant_id = a.variant_id AND b.promotion_id > a.promotion_id
             JOIN pricing_promotion pa ON pa.id = a.promotion_id JOIN pricing_promotion pb ON pb.id = b.promotion_id WHERE pa.starts_at < pb.ends_at AND pb.starts_at < pa.ends_at',
        )));
        self::assertSame(0, $this->int($connection->fetchOne('SELECT count(*) FROM pricing_promotion_variant v WHERE NOT EXISTS (SELECT 1 FROM pricing_variant_price p WHERE p.variant_id = v.variant_id)')));
        // A price is locked while its promotion runs: no staff change inside a promotion of the variant.
        self::assertSame(0, $this->int($connection->fetchOne(
            "SELECT count(*) FROM pricing_price_history h JOIN pricing_promotion_variant v ON v.variant_id = h.variant_id JOIN pricing_promotion p ON p.id = v.promotion_id WHERE h.source = 'staff' AND h.changed_at >= p.starts_at AND h.changed_at < p.ends_at",
        )));
    }

    public function testThePromotionsAndTheCodesReconstituteThroughTheRepositories(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $promotions = new DoctrinePromotionRepository($entityManager);
        $codes = new DoctrineDiscountCodeRepository($entityManager);

        $ids = array_map($this->text(...), $this->connection()->fetchFirstColumn('SELECT id FROM pricing_promotion ORDER BY id'));
        foreach ($ids as $id) {
            $promotion = $promotions->find(PromotionId::fromString($id));
            self::assertNotNull($promotion, $id);
            self::assertSame(PromotionStatus::Ended, $promotion->status(self::anchor()));
            self::assertNotSame([], $promotion->variantIds());
            self::assertLessThanOrEqual(1_000, \count($promotion->variantIds()));
        }

        $codeIds = array_map($this->text(...), $this->connection()->fetchFirstColumn('SELECT id FROM pricing_discount_code ORDER BY id'));
        self::assertCount(\count(new DiscountCodePlan(self::NAMESPACE, self::anchor())->codes()), $codeIds);
        foreach ($codeIds as $id) {
            $code = $codes->find(DiscountCodeId::fromString($id));
            self::assertNotNull($code, $id);
            self::assertSame(DiscountCodeStatus::Expired, $code->status(self::anchor()));
        }
    }

    public function testThePromotionRowsAreThePlanOfThePromotionPlanner(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();
        $profiles = Profiles::fromFile(\dirname(__DIR__, 3).'/fixtures/demo-data/profiles.yaml');
        $dictionaries = new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data');
        $planned = new PromotionPlanner($dictionaries, new ProductGenerator($dictionaries, $profiles->namespace, self::anchor(), 2_000, $profiles->partSize), $profiles->namespace, self::anchor(), 2_000, $profiles->partSize)->plan();

        $plannedIds = array_map(static fn (PromotionSpec $promotion): string => $promotion->id, $planned);
        sort($plannedIds);
        self::assertSame($plannedIds, array_map($this->text(...), $this->connection()->fetchFirstColumn('SELECT id FROM pricing_promotion ORDER BY id')));
        $first = $planned[0];
        self::assertSame($first->name, $this->connection()->fetchOne('SELECT name FROM pricing_promotion WHERE id = ?', [$first->id]));
        self::assertSame(\count($first->variantIds), $this->int($this->connection()->fetchOne('SELECT count(*) FROM pricing_promotion_variant WHERE promotion_id = ?', [$first->id])));
    }

    public function testTwoRunsWithTheSameAnchorGiveTheSameRowsInThePricingTables(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();
        $first = $this->pricingChecksums();
        self::assertNotContains('empty', $first);

        $this->emptyPricing();
        $this->pricingSteps();

        self::assertSame($first, $this->pricingChecksums());
    }

    public function testThePriceStepSplitIntoWorkersGivesTheSameRowsAsOneProcess(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();
        $whole = $this->pricingChecksums();

        $this->emptyPricing();
        $this->succeeds('demo-data:prices', ['--worker' => 0, '--workers' => 3]);
        $this->succeeds('demo-data:prices', ['--worker' => 2, '--workers' => 3]);
        $this->succeeds('demo-data:prices', ['--worker' => 1, '--workers' => 3]);
        $this->succeeds('demo-data:promotions');

        self::assertSame($whole, $this->pricingChecksums());
    }

    public function testAnotherAnchorMovesTheTimes(): void
    {
        $this->catalogSteps();
        $this->pricingSteps();
        $first = $this->text($this->connection()->fetchOne('SELECT max(changed_at) FROM pricing_price_history'));

        $this->emptyPricing();
        $this->succeeds('demo-data:prices', ['--anchor' => '2026-12-17T00:00:00Z']);
        $this->succeeds('demo-data:promotions', ['--anchor' => '2026-12-17T00:00:00Z']);

        self::assertNotSame($first, $this->text($this->connection()->fetchOne('SELECT max(changed_at) FROM pricing_price_history')));
    }

    private function emptyPricing(): void
    {
        $this->connection()->executeStatement('TRUNCATE pricing_variant_price, pricing_price_history, pricing_promotion_variant, pricing_promotion, pricing_discount_code_usage, pricing_discount_code');
    }

    /**
     * @return array<string, string>
     */
    private function pricingChecksums(): array
    {
        $sums = [];
        $orders = ['pricing_variant_price' => 't.variant_id', 'pricing_price_history' => 't.id', 'pricing_promotion' => 't.id', 'pricing_promotion_variant' => 't.promotion_id, t.variant_id', 'pricing_discount_code' => 't.id'];
        foreach ($orders as $table => $order) {
            $sums[$table] = $this->text($this->connection()->fetchOne(\sprintf("SELECT coalesce(md5(string_agg(t::text, '|' ORDER BY %s)), 'empty') FROM %s t", $order, $table)));
        }

        return $sums;
    }

    public function testThePricesNeedTheProducts(): void
    {
        $tester = $this->step('demo-data:prices');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('demo-data:products', $tester->getDisplay());
        self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM pricing_variant_price')));
    }

    public function testThePartsAreListedAndAnUnknownProfileIsRefused(): void
    {
        self::assertSame("4\n", $this->succeeds('demo-data:prices', ['--list-parts' => true])->getDisplay());
        self::assertSame(Command::FAILURE, $this->step('demo-data:prices', ['--demo-profile' => 'huge'])->getStatusCode());
        self::assertSame(Command::FAILURE, $this->step('demo-data:promotions', ['--demo-profile' => 'huge'])->getStatusCode());
    }
}
