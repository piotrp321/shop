<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Concurrency;

use App\Pricing\Application\Command\PlanPromotion;
use App\Pricing\Application\Command\SetVariantPrice;
use App\Pricing\Domain\Promotion\PriceLockedByPromotion;
use App\Pricing\Domain\Promotion\PromotionOverlap;
use App\Tests\Integration\Inventory\CatalogFixture;
use App\Tests\Integration\Inventory\Concurrency\ConcurrentCommands;
use App\Tests\Integration\Inventory\Concurrency\Outcome;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Writes of promotions and of prices at the same moment on PostgreSQL, each participant a process of its own
 * dispatching on command.bus (ConcurrentCommands of the Inventory tests). The data commits, so it is deleted after the
 * test. The lock of the promotion writes serialises them: two overlapping promotions never both get in (Q4), and a
 * price never changes inside an active promotion (Q13).
 */
final class PromotionConcurrencyTest extends KernelTestCase
{
    private const int ROUNDS = 5;

    private Connection $control;
    /** @var list<string> */
    private array $products = [];
    /** @var list<string> */
    private array $variants = [];

    protected function setUp(): void
    {
        // Boots the kernel, so its cache is warm before the participants boot theirs.
        $kernelConnection = self::getContainer()->get('doctrine.dbal.default_connection');
        \assert($kernelConnection instanceof Connection);
        /** @var array{driver: 'pdo_pgsql', host: string, dbname: string, user: string, password: string} $params */
        $params = $kernelConnection->getParams();
        $this->control = DriverManager::getConnection($params, new DbalConfiguration());
        $this->control->executeStatement("SET lock_timeout = '10s'");
    }

    protected function tearDown(): void
    {
        if ([] !== $this->variants) {
            $this->control->executeStatement('DELETE FROM pricing_promotion WHERE id IN (SELECT promotion_id FROM pricing_promotion_variant WHERE variant_id IN (?))', [$this->variants], [ArrayParameterType::STRING]);
            $this->control->executeStatement('DELETE FROM pricing_price_history WHERE variant_id IN (?)', [$this->variants], [ArrayParameterType::STRING]);
            $this->control->executeStatement('DELETE FROM pricing_variant_price WHERE variant_id IN (?)', [$this->variants], [ArrayParameterType::STRING]);
        }
        CatalogFixture::delete($this->control, $this->products);
        $this->control->close();
        parent::tearDown();
    }

    public function testTwoOverlappingPromotionsOfACommonVariantWrittenAtOnceGiveOneSuccessAndAnOverlap(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            [$common, $a, $b] = [$this->pricedVariant(), $this->pricedVariant(), $this->pricedVariant()];
            $first = Uuid::v7()->toRfc4122();
            $second = Uuid::v7()->toRfc4122();

            $outcomes = ConcurrentCommands::run($this->control, [
                PlanPromotion::byStaff($first, 'Pierwsza', 10, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+10 days'), [$a, $common], 'sub-1', 'Anna'),
                PlanPromotion::byStaff($second, 'Druga', 20, new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('+15 days'), [$common, $b], 'sub-2', 'Bartek'),
            ]);

            $message = 'round '.$round.': '.self::messages($outcomes);
            self::assertSame([PromotionOverlap::class => 1, Outcome::SUCCESS => 1], self::kinds($outcomes), $message);
            self::assertSame(1, $this->control->fetchOne('SELECT count(*) FROM pricing_promotion WHERE id IN (?, ?)', [$first, $second]), $message);
            self::assertSame(1, $this->control->fetchOne('SELECT count(*) FROM pricing_promotion_variant WHERE variant_id = ?', [$common]), $message);
        }
    }

    public function testAPriceChangedAtOnceWithAPromotionFromNowChangesBeforeItsStartOrIsLocked(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->pricedVariant();
            $promotion = Uuid::v7()->toRfc4122();

            $outcomes = ConcurrentCommands::run($this->control, [
                PlanPromotion::byStaff($promotion, 'Od teraz', 20, null, new \DateTimeImmutable('+1 day'), [$variant], 'sub-1', 'Anna'),
                SetVariantPrice::byStaff($variant, 2460, 1, 'sub-2', 'Bartek'),
            ]);

            $message = 'round '.$round.': '.self::messages($outcomes);
            self::assertSame(Outcome::SUCCESS, $outcomes[0]->kind, $message);
            self::assertContains($outcomes[1]->kind, [Outcome::SUCCESS, PriceLockedByPromotion::class], $message);
            $startsAt = $this->control->fetchOne('SELECT starts_at FROM pricing_promotion WHERE id = ?', [$promotion]);
            $changes = $this->control->fetchFirstColumn("SELECT changed_at FROM pricing_price_history WHERE variant_id = ? AND source = 'staff'", [$variant]);
            if (Outcome::SUCCESS === $outcomes[1]->kind) {
                self::assertCount(1, $changes, $message);
                self::assertTrue($this->control->fetchOne('SELECT CAST(? AS timestamptz) < CAST(? AS timestamptz)', [$changes[0], $startsAt]), $message.': the price changed at '.var_export($changes[0], true).', the promotion starts at '.var_export($startsAt, true));
            } else {
                self::assertSame([], $changes, $message);
                self::assertSame(1230, $this->control->fetchOne('SELECT gross_amount FROM pricing_variant_price WHERE variant_id = ?', [$variant]), $message);
            }
        }
    }

    public function testAPriceOfAVariantInAnActivePromotionIsLockedForEveryConcurrentChange(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $variant = $this->pricedVariant();
            $this->control->executeStatement(
                "INSERT INTO pricing_promotion (id, name, percent_off, starts_at, ends_at, created_at, updated_at, version) VALUES (?, 'Aktywna', 20, now() - interval '1 hour', now() + interval '1 day', now(), now(), 1)",
                [$promotion = Uuid::v7()->toRfc4122()],
            );
            $this->control->executeStatement('INSERT INTO pricing_promotion_variant (promotion_id, variant_id) VALUES (?, ?)', [$promotion, $variant]);

            $outcomes = ConcurrentCommands::run($this->control, [
                SetVariantPrice::byStaff($variant, 2460, 1, 'sub-1', 'Anna'),
                SetVariantPrice::byStaff($variant, 3690, 1, 'sub-2', 'Bartek'),
            ]);

            $message = 'round '.$round.': '.self::messages($outcomes);
            self::assertSame([PriceLockedByPromotion::class => 2], self::kinds($outcomes), $message);
            self::assertSame(1230, $this->control->fetchOne('SELECT gross_amount FROM pricing_variant_price WHERE variant_id = ?', [$variant]), $message);
        }
    }

    /**
     * A published variant with a price of 12.30 zł at version 1, committed.
     */
    private function pricedVariant(): string
    {
        $product = CatalogFixture::product($this->control);
        $variant = CatalogFixture::defaultVariant($this->control, $product);
        $this->products[] = $product;
        $this->variants[] = $variant;
        $this->control->executeStatement(
            "INSERT INTO pricing_variant_price (variant_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version, updated_at) VALUES (?, 'PLN', 1230, 1000, 230, 2300, 'standard', 1, now() - interval '1 day')",
            [$variant],
        );

        return $variant;
    }

    /**
     * @param list<Outcome> $outcomes
     *
     * @return array<string, int>
     */
    private static function kinds(array $outcomes): array
    {
        $kinds = array_count_values(array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes));
        ksort($kinds);

        return $kinds;
    }

    /**
     * @param list<Outcome> $outcomes
     */
    private static function messages(array $outcomes): string
    {
        return implode('; ', array_map(static fn (Outcome $outcome): string => $outcome->kind.(null === $outcome->message ? '' : ' '.$outcome->message), $outcomes));
    }
}
