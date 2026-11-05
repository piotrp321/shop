<?php

declare(strict_types=1);

namespace App\Cart\UI\Http;

use App\Cart\Application\Query\GetProductOffers;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The product ids of GET /api/v1/product-offers, taken from the repeated `productId` key. The violation messages
 * never contain the submitted value.
 */
final readonly class ProductOffersQuery
{
    /**
     * @param list<string> $productId
     */
    public function __construct(
        #[Assert\Count(min: 1, max: GetProductOffers::MAX_PRODUCTS)]
        #[Assert\All([new Assert\NotBlank(), new Assert\Uuid()])]
        public array $productId = [],
    ) {
    }
}
