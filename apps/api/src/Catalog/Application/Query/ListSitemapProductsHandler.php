<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\SitemapProductSource;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListSitemapProductsHandler
{
    public function __construct(private SitemapProductSource $source)
    {
    }

    /**
     * @throws InvalidSitemapChunk
     */
    public function __invoke(ListSitemapProducts $query): SitemapProductList
    {
        $products = $this->source->publishedInRange(SitemapChunkKey::decode($query->chunk));

        return new SitemapProductList(array_map(
            static fn (array $product): SitemapProduct => new SitemapProduct(
                $product['slug'],
                $product['updatedAt']->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
            ),
            $products,
        ));
    }
}
