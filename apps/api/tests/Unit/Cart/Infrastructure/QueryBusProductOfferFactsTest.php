<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cart\Infrastructure;

use App\Cart\Application\Query\GetProductOffers;
use App\Cart\Application\View\ProductOfferView;
use App\Cart\Application\View\VariantOfferView;
use App\Cart\Infrastructure\Catalog\QueryBusProductOfferFacts;
use App\Catalog\Application\Search\OfferFactsUnavailable;
use App\Catalog\Application\Search\ProductFacts;
use App\Catalog\Application\Search\VariantFacts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

final class QueryBusProductOfferFactsTest extends TestCase
{
    /** @var list<list<string>> the product ids of every GetProductOffers */
    private array $asked = [];

    private ?\Throwable $failure = null;

    public function testAsksInBatchesOfTheQueryLimitAndMergesTheFacts(): void
    {
        $ids = array_map(static fn (int $n): string => \sprintf('00000000-0000-4000-8000-%012d', $n), range(1, 97));

        $facts = $this->facts()->of($ids);

        self::assertSame([48, 48, 1], array_map(\count(...), $this->asked));
        self::assertSame($ids, array_merge(...$this->asked));
        self::assertSame($ids, array_keys($facts));
        self::assertEquals(new ProductFacts(1_000, [
            '10000000-0000-4000-8000-000000000001' => new VariantFacts(1_000, true),
            '10000000-0000-4000-8000-000000000002' => new VariantFacts(null, false),
        ]), $facts[$ids[96]]);
    }

    public function testARepeatedIdIsAskedOnce(): void
    {
        $id = '00000000-0000-4000-8000-000000000001';

        $facts = $this->facts()->of([$id, $id]);

        self::assertSame([[$id]], $this->asked);
        self::assertSame([$id], array_keys($facts));
    }

    public function testNoProductsAsksNothing(): void
    {
        self::assertSame([], $this->facts()->of([]));
        self::assertSame([], $this->asked);
    }

    public function testAFailedQueryIsOfferFactsUnavailableWithTheCause(): void
    {
        $this->failure = new \RuntimeException('Connection lost.');

        try {
            $this->facts()->of(['00000000-0000-4000-8000-000000000001']);
            self::fail('The failure was swallowed.');
        } catch (OfferFactsUnavailable $e) {
            self::assertSame($this->failure, $e->getPrevious());
        }
    }

    private function facts(): QueryBusProductOfferFacts
    {
        return new QueryBusProductOfferFacts(new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            GetProductOffers::class => [function (GetProductOffers $query): array {
                if (null !== $this->failure) {
                    throw $this->failure;
                }
                $this->asked[] = $query->productIds;

                return array_map(static fn (string $id): ProductOfferView => new ProductOfferView($id, [], 1_000, false, 'PLN', 'in_stock', [
                    new VariantOfferView('10000000-0000-4000-8000-000000000001', '', 1_000, 'in_stock', true),
                    new VariantOfferView('10000000-0000-4000-8000-000000000002', '', null, 'out_of_stock', false),
                ]), $query->productIds);
            }],
        ]))]));
    }
}
