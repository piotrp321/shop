<?php

declare(strict_types=1);

namespace App\Tests\Functional\DemoData;

use App\DemoData\Catalog\Dictionaries;
use App\DemoData\Catalog\ProductGenerator;
use App\DemoData\Pricing\PriceGenerator;
use App\DemoData\Pricing\PriceTimeline;
use App\DemoData\Pricing\PromotionPlanner;
use App\DemoData\Pricing\PromotionSpec;
use App\DemoData\Profile\Profiles;
use App\Pricing\Application\Query\GetCurrentPrices;
use App\Pricing\Application\Query\PriceSnapshot;
use App\Pricing\Domain\Price\GrossPrice;
use App\Pricing\Domain\Promotion\LowestPriorPrice;
use App\Pricing\Domain\Promotion\PriorPrice;
use App\Pricing\Domain\Promotion\PriorPromotion;
use App\Tests\Integration\DemoData\RunsDemoDataSteps;
use App\Tests\Support\Security\TestTokenFactory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The generated prices on the real path of the Omnibus price (profile small): after a promotion is planned through the
 * API of the panel on a variant of the tail that has a recent generated price change and ended generated promotions,
 * the lowest prior price that Pricing returns is the one the generated history says. The anchor is the start of today
 * (the API reads the real clock, so the generated moments have to be around it); the steps run in the process of the
 * test and its transaction.
 */
final class GeneratedPricesOnTheRealPathsTest extends WebTestCase
{
    use RunsDemoDataSteps;

    private KernelBrowser $client;
    private \DateTimeImmutable $anchor;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // The container must survive between the steps and the requests: they share one connection and its transaction.
        $this->client->disableReboot();
        self::assertSame(0, $this->int($this->connection()->fetchOne('SELECT count(*) FROM catalog_product')), 'shop_test has catalog rows that are not the ones of this test.');

        $this->anchor = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        foreach (['demo-data:categories', 'demo-data:products', 'demo-data:prices', 'demo-data:promotions'] as $step) {
            $this->succeeds($step, ['--anchor' => $this->anchor->format(\DATE_ATOM)]);
        }
    }

    public function testTheOmnibusPriceAfterPlanningAPromotionIsTheOneOfTheGeneratedHistory(): void
    {
        [$variantId, $timeline] = $this->variantWithARecentChangeAndEndedPromotions();

        $promotion = $this->planPromotionFromNow($variantId);
        $snapshot = $this->currentPriceOnceTheStartIsReached($variantId, new \DateTimeImmutable($promotion['startsAt']));

        self::assertNotNull($snapshot->promotion, 'The promotion is running.');
        $start = new \DateTimeImmutable($promotion['startsAt']);
        $expected = $this->lowestPriorByTheTimeline($timeline, $start);
        // The window holds a promotional price, so there is a lowest price and it is below the catalog price before the change.
        self::assertNotNull($expected);
        self::assertLessThan($timeline->entries()[0]->gross, $expected);
        self::assertSame($expected, $snapshot->promotion->lowestPriorGross);
        // The same value by the rule of Pricing, from the entries and promotions of the generator.
        self::assertSame(
            LowestPriorPrice::of(
                $start,
                array_map(static fn ($entry): PriorPrice => new PriorPrice($entry->changedAt, GrossPrice::pln($entry->gross)), $timeline->entries()),
                array_map(static fn (PromotionSpec $ended): PriorPromotion => new PriorPromotion($ended->period, $ended->percentOff), $timeline->promotions()),
            )?->amount(),
            $snapshot->promotion->lowestPriorGross,
        );
    }

    /**
     * A variant of a published product of the tail with a change of its price in the 29 days before the anchor, in a
     * generated promotion that ended inside the 30 days before the start the test plans (so the window of the Omnibus
     * price holds the change and a promotional price).
     *
     * @return array{string, PriceTimeline}
     */
    private function variantWithARecentChangeAndEndedPromotions(): array
    {
        $profiles = Profiles::fromFile(\dirname(__DIR__, 3).'/fixtures/demo-data/profiles.yaml');
        $dictionaries = new Dictionaries(\dirname(__DIR__, 3).'/fixtures/demo-data');
        $products = new ProductGenerator($dictionaries, $profiles->namespace, $this->anchor, 2_000, $profiles->partSize);
        $prices = new PriceGenerator($dictionaries, $profiles->namespace, $this->anchor, 20);
        $byVariant = PromotionPlanner::byVariant(new PromotionPlanner($dictionaries, $products, $profiles->namespace, $this->anchor, 2_000, $profiles->partSize)->plan());

        $recent = $this->anchor->modify('-29 days');
        for ($part = 0; $part < 4; ++$part) {
            foreach ($products->part($part) as $product) {
                foreach ($prices->forProduct($product, $byVariant) as $position => $timeline) {
                    $entries = $timeline->entries();
                    $ended = array_filter($timeline->promotions(), static fn (PromotionSpec $promotion): bool => $promotion->period->endsAt() > $recent);
                    if (null !== $product->firstPublishedAt && 2 === \count($entries) && $entries[1]->changedAt >= $recent && [] !== $ended) {
                        return [$product->variants[$position]->id, $timeline];
                    }
                }
            }
        }

        self::fail('The generated data has no variant with a change in the last 29 days and a promotion that ended in them.');
    }

    /**
     * @return array<mixed>
     */
    private function planPromotionFromNow(string $variantId): array
    {
        $this->client->request(
            'POST',
            '/api/v1/admin/promotions',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.TestTokenFactory::staff(['marketing'])],
            content: json_encode(['name' => 'Test Omnibus', 'percentOff' => 10, 'startsAt' => null, 'endsAt' => new \DateTimeImmutable('+3 days')->format(\DATE_ATOM), 'variantIds' => [$variantId]], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function currentPriceOnceTheStartIsReached(string $variantId, \DateTimeImmutable $start): PriceSnapshot
    {
        // A promotion "from now" starts at the next second.
        while (new \DateTimeImmutable() < $start) {
            usleep(100_000);
        }

        $bus = self::getContainer()->get('query.bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $stamp = $bus->dispatch(new GetCurrentPrices([$variantId], true))->last(HandledStamp::class);
        self::assertInstanceOf(HandledStamp::class, $stamp);
        $result = $stamp->getResult();
        self::assertIsArray($result);
        self::assertCount(1, $result);
        self::assertInstanceOf(PriceSnapshot::class, $result[0]);

        return $result[0];
    }

    /**
     * The lowest effective price in [start - 30 days, start) by the effective-price function of the generator, at the
     * start of the window and at every moment the price or a promotion of the variant changes inside it.
     */
    private function lowestPriorByTheTimeline(PriceTimeline $timeline, \DateTimeImmutable $start): ?int
    {
        $from = $start->modify('-30 days');
        $moments = [$from];
        foreach ($timeline->entries() as $entry) {
            $moments[] = $entry->changedAt;
        }
        foreach ($timeline->promotions() as $promotion) {
            $moments[] = $promotion->period->startsAt();
            $moments[] = $promotion->period->endsAt();
        }

        $lowest = null;
        foreach ($moments as $moment) {
            if ($moment < $from || $moment >= $start) {
                continue;
            }
            $price = $timeline->effectiveAt($moment)?->effective->amount();
            if (null !== $price && (null === $lowest || $price < $lowest)) {
                $lowest = $price;
            }
        }

        return $lowest;
    }
}
