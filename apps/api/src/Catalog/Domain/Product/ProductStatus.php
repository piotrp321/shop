<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

/**
 * Whether the storefront shows a product (decision K1): a draft is seen only in the panel. Products are never
 * deleted; unpublishing makes a published product a draft again.
 */
enum ProductStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
