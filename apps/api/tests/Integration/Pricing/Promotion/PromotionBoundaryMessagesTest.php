<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pricing\Promotion;

use App\Pricing\Application\Command\PlanPromotion;
use App\Pricing\Domain\Promotion\PromotionPricesChanged;
use App\Pricing\Infrastructure\SystemClock;
use App\Shared\Infrastructure\Http\Cache\HttpStorefrontCache;
use App\Tests\Integration\Inventory\CatalogFixture;
use App\Tests\Integration\Shared\Messaging\MessagingKernelTestCase;
use App\Tests\Support\InMemory\SpyStorefrontCache;
use App\Tests\Unit\Pricing\FixedPricingClock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The boundaries of a promotion on the Doctrine transport `promotions` in shop_test (the `test_messaging` kernel;
 * rolled back after each test): the write of the promotion stores a delayed PromotionBoundaryReached per boundary,
 * the worker leaves it until its moment and then publishes PromotionPricesChanged of the product through the outbox
 * and RabbitMQ to the invalidation of the store (a spy). The transport compares available_at with the system time, so
 * the test moves available_at by SQL and the clock of Pricing with it.
 */
final class PromotionBoundaryMessagesTest extends MessagingKernelTestCase
{
    private Connection $db;
    private FixedPricingClock $clock;
    private SpyStorefrontCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->connection();
        $this->clock = new FixedPricingClock('@'.time());
        self::getContainer()->set(SystemClock::class, $this->clock);
        $this->cache = new SpyStorefrontCache();
        self::getContainer()->set(HttpStorefrontCache::class, $this->cache);
    }

    public function testADelayedBoundaryIsHandledAtItsTime(): void
    {
        [$productId, $variantId] = $this->pricedVariant();
        $startsAt = new \DateTimeImmutable('@'.(time() + 3600));
        $endsAt = $startsAt->modify('+1 day');
        $promotionId = $this->plan($variantId, $startsAt, $endsAt);

        self::assertSame(2, $this->rows('promotions'), 'one message per boundary');
        self::assertContains($this->availableAt($startsAt), [$startsAt->getTimestamp(), $startsAt->getTimestamp() + 1]);
        self::assertContains($this->availableAt($endsAt), [$endsAt->getTimestamp(), $endsAt->getTimestamp() + 1]);

        // Before the start the worker finds nothing to handle.
        $this->consume('promotions', 1);
        self::assertSame(2, $this->rows('promotions'));
        self::assertSame(0, $this->priceEvents($productId));

        // The start has come.
        $this->moveToNow($startsAt);
        $this->clock->now = $startsAt->modify('+1 second');
        $this->consume('promotions');

        self::assertSame(1, $this->rows('promotions'), 'the start was acknowledged, the end waits');
        self::assertSame(1, $this->priceEvents($productId));
        self::assertSame(0, $this->rows('failed'));

        // The event goes through RabbitMQ to the consumers of Pricing.
        $this->consume('outbox');
        $this->consume('events');
        self::assertSame(['product:'.$productId], $this->cache->calls);
        self::assertSame(0, $this->eventsInQueue());
        self::assertSame(0, $this->rows('failed'), 'promotion '.$promotionId);
    }

    public function testABoundaryThatCameTooEarlyIsStoredAgainForASecondAfterIt(): void
    {
        [$productId, $variantId] = $this->pricedVariant();
        $startsAt = new \DateTimeImmutable('@'.(time() + 3600));
        $this->plan($variantId, $startsAt, $startsAt->modify('+1 day'));
        $this->moveToNow($startsAt);
        $this->clock->now = $startsAt->modify('-1 second');

        $this->consume('promotions');

        self::assertSame(2, $this->rows('promotions'), 'the delivered message was acknowledged and a new one stored');
        self::assertContains($this->availableAt($startsAt->modify('+1 second')), [$startsAt->getTimestamp() + 1, $startsAt->getTimestamp() + 2]);
        self::assertSame(0, $this->priceEvents($productId));
    }

    /**
     * @return array{string, string} a published product and its variant with a current price
     */
    private function pricedVariant(): array
    {
        $productId = CatalogFixture::product($this->db);
        $variantId = CatalogFixture::defaultVariant($this->db, $productId);
        $this->db->executeStatement(
            "INSERT INTO pricing_variant_price (variant_id, currency, gross_amount, net_amount, vat_amount, vat_rate_bp, tax_class, version, updated_at) VALUES (?, 'PLN', 1230, 1000, 230, 2300, 'standard', 1, now() - interval '1 day')",
            [$variantId],
        );

        return [$productId, $variantId];
    }

    private function plan(string $variantId, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt): string
    {
        $promotionId = Uuid::v7()->toRfc4122();
        $bus = self::getContainer()->get('command.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(PlanPromotion::byStaff($promotionId, 'Jesień', 20, $startsAt, $endsAt, [$variantId], 'sub-1', 'Anna Marketing'));

        return $promotionId;
    }

    /**
     * The message of the boundary becomes available now, as if its moment had come.
     */
    private function moveToNow(\DateTimeImmutable $boundary): void
    {
        $this->db->executeStatement(
            "UPDATE messenger_messages SET available_at = (now() AT TIME ZONE 'UTC') - interval '1 second' WHERE queue_name = 'promotions' AND available_at <= ?",
            [$boundary->modify('+1 second')->format('Y-m-d H:i:s')],
        );
    }

    /**
     * @return int the moment, in seconds, the message of the promotion transport due nearest the boundary is available
     */
    private function availableAt(\DateTimeImmutable $boundary): int
    {
        $available = $this->db->fetchOne(
            "SELECT EXTRACT(EPOCH FROM available_at)::bigint FROM messenger_messages WHERE queue_name = 'promotions' ORDER BY abs(EXTRACT(EPOCH FROM available_at) - ?) LIMIT 1",
            [$boundary->getTimestamp()],
        );
        self::assertIsInt($available);

        return $available;
    }

    private function priceEvents(string $productId): int
    {
        $count = $this->db->fetchOne(
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'outbox' AND position(? IN body) > 0 AND position(? IN body) > 0",
            // The body escapes the backslashes of the class name.
            [addslashes(PromotionPricesChanged::class), $productId],
        );
        self::assertIsInt($count);

        return $count;
    }
}
