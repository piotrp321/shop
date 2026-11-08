<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing\Infrastructure;

use App\Catalog\Application\Query\GetVariantSummaries;
use App\Catalog\Application\Query\VariantSummary as CatalogVariantSummary;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Infrastructure\Catalog\QueryBusVariantSummaries;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class QueryBusVariantSummariesTest extends TestCase
{
    public function testAsksCatalogInBatchesOfItsLimitAndKeysTheKnownVariantsById(): void
    {
        $ids = array_map(static fn (int $i): string => \sprintf('0192f0c4-7c1a-7c4e-9a6e-%012x', $i), range(1, 250));
        $bus = new class implements MessageBusInterface {
            /** @var list<list<string>> */
            public array $batches = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                \assert($message instanceof GetVariantSummaries);
                $this->batches[] = $message->variantIds;
                // Catalog leaves out the variants it does not know: here every third one.
                $known = array_values(array_filter($message->variantIds, static fn (string $id): bool => 0 !== hexdec(substr($id, -3)) % 3));
                $summaries = array_map(static fn (string $id): CatalogVariantSummary => new CatalogVariantSummary($id, 'product-of-'.$id, 'Namiot', 'Zielony', null), $known);

                return new Envelope($message, [new HandledStamp($summaries, 'handler')]);
            }
        };

        $summaries = new QueryBusVariantSummaries($bus)->of(array_map(VariantId::fromString(...), $ids));

        self::assertSame([100, 100, 50], array_map(\count(...), $bus->batches));
        self::assertSame($ids, array_merge(...$bus->batches));
        self::assertCount(167, $summaries);
        $first = $summaries[$ids[0]];
        self::assertSame([$ids[0], 'product-of-'.$ids[0], 'Namiot', 'Zielony'], [$first->variantId->toString(), $first->productId, $first->productName, $first->label]);
        self::assertArrayNotHasKey($ids[2], $summaries);
    }

    public function testNoVariantsAskNothing(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        self::assertSame([], new QueryBusVariantSummaries($bus)->of([]));
    }
}
