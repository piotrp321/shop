<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The product ids of GET /api/v1/prices, taken from the repeated `productId` key. The violation messages never
 * contain the submitted value.
 */
final readonly class CurrentPricesQuery
{
    public const int MAX_PRODUCTS = 48;

    /**
     * @param list<string> $productId
     */
    public function __construct(
        #[Assert\Count(min: 1, max: self::MAX_PRODUCTS)]
        #[Assert\All([new Assert\NotBlank(), new Assert\Uuid()])]
        public array $productId = [],
    ) {
    }
}
