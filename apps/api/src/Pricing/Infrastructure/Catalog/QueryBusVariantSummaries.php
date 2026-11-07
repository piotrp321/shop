<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Catalog;

use App\Catalog\Application\Query\GetVariantSummaries;
use App\Catalog\Application\Query\VariantSummary as CatalogVariantSummary;
use App\Pricing\Application\Port\VariantSummaries;
use App\Pricing\Application\Port\VariantSummary;
use App\Pricing\Domain\Price\VariantId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Catalog GetVariantSummaries on the query bus, in batches of its limit (GetVariantSummaries::MAX_VARIANTS), so a
 * promotion of up to 1000 variants needs up to 10 reads and the contract of Catalog stays as it is.
 */
final class QueryBusVariantSummaries implements VariantSummaries
{
    use HandleTrait;

    public function __construct(#[Autowire(service: 'query.bus')] MessageBusInterface $queryBus)
    {
        $this->messageBus = $queryBus;
    }

    public function of(array $variantIds): array
    {
        $summaries = [];
        foreach (array_chunk($variantIds, GetVariantSummaries::MAX_VARIANTS) as $batch) {
            $found = $this->ask(new GetVariantSummaries(array_map(static fn (VariantId $id): string => $id->toString(), $batch)));
            \assert(\is_array($found));
            foreach ($found as $summary) {
                \assert($summary instanceof CatalogVariantSummary);
                $summaries[$summary->variantId] = new VariantSummary(VariantId::fromString($summary->variantId), $summary->productId, $summary->name, $summary->label);
            }
        }

        return $summaries;
    }

    private function ask(object $query): mixed
    {
        try {
            return $this->handle($query);
        } catch (HandlerFailedException $failure) {
            // The bus wraps what the handler threw; the caller deals with the cause (e.g. a lost connection).
            $wrapped = $failure->getWrappedExceptions();

            throw 1 === \count($wrapped) ? $wrapped[array_key_first($wrapped)] : $failure;
        }
    }
}
