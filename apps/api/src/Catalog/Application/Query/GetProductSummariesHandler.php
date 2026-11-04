<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Image\ListingImage;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Port\ProductSummarySource;
use App\Catalog\Domain\Product\ProductId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetProductSummariesHandler
{
    public function __construct(
        private ProductSummarySource $source,
        private ImageUrlGenerator $imageUrls,
    ) {
    }

    /**
     * One read for all the products. The summaries follow the order of the request; a repeated id answers once,
     * a product that does not exist is left out.
     *
     * @return list<ProductSummary>
     *
     * @throws InvalidProductSummariesQuery
     */
    public function __invoke(GetProductSummaries $query): array
    {
        $count = \count($query->productIds);
        if ($count < 1 || $count > GetProductSummaries::MAX_PRODUCTS) {
            throw InvalidProductSummariesQuery::productCountOutOfRange();
        }

        $ids = [];
        foreach ($query->productIds as $raw) {
            try {
                $id = ProductId::fromString($raw);
            } catch (\InvalidArgumentException) {
                throw InvalidProductSummariesQuery::notAProductId();
            }
            $ids[$id->toString()] = $id;
        }

        $found = [];
        foreach ($this->source->findMany(array_values($ids)) as $row) {
            $found[$row['id']] = $row;
        }

        $summaries = [];
        foreach (array_keys($ids) as $key) {
            $row = $found[$key] ?? null;
            if (null === $row) {
                continue;
            }
            $image = $row['mainImage'];
            $summaries[] = new ProductSummary(
                $key,
                $row['name'],
                null === $image ? null : ListingImage::view($this->imageUrls, $image['key'], $image['alt']),
            );
        }

        return $summaries;
    }
}
