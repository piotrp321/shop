<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Image\ListingImage;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Port\VariantSource;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetVariantSummariesHandler
{
    public function __construct(
        private VariantSource $source,
        private ImageUrlGenerator $imageUrls,
    ) {
    }

    /**
     * One read for all the variants. The summaries follow the order of the request; a repeated id answers once,
     * a variant that does not exist is left out.
     *
     * @return list<VariantSummary>
     *
     * @throws InvalidVariantSummariesQuery
     */
    public function __invoke(GetVariantSummaries $query): array
    {
        $count = \count($query->variantIds);
        if ($count < 1 || $count > GetVariantSummaries::MAX_VARIANTS) {
            throw InvalidVariantSummariesQuery::variantCountOutOfRange();
        }

        $ids = [];
        foreach ($query->variantIds as $raw) {
            try {
                $id = VariantId::fromString($raw);
            } catch (\InvalidArgumentException) {
                throw InvalidVariantSummariesQuery::notAVariantId();
            }
            $ids[$id->toString()] = $id;
        }

        $found = [];
        foreach ($this->source->summaries(array_values($ids)) as $row) {
            $found[$row['id']] = $row;
        }

        $summaries = [];
        foreach (array_keys($ids) as $key) {
            $row = $found[$key] ?? null;
            if (null === $row) {
                continue;
            }
            $image = $row['mainImage'];
            $summaries[] = new VariantSummary(
                $key,
                $row['productId'],
                $row['name'],
                new ProductVariant($ids[$key], VariantValues::fromList($row['values']))->label(),
                null === $image ? null : ListingImage::view($this->imageUrls, $image['key'], $image['alt']),
            );
        }

        return $summaries;
    }
}
