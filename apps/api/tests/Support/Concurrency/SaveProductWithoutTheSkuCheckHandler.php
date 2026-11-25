<?php

declare(strict_types=1);

namespace App\Tests\Support\Concurrency;

use App\Catalog\Domain\Category\CategoryId;
use App\Catalog\Domain\Product\Ean;
use App\Catalog\Domain\Product\Product;
use App\Catalog\Domain\Product\ProductBrand;
use App\Catalog\Domain\Product\ProductCategories;
use App\Catalog\Domain\Product\ProductDescription;
use App\Catalog\Domain\Product\ProductId;
use App\Catalog\Domain\Product\ProductImages;
use App\Catalog\Domain\Product\ProductName;
use App\Catalog\Domain\Product\ProductParameters;
use App\Catalog\Domain\Product\ProductRepository;
use App\Catalog\Domain\Product\ProductStatus;
use App\Catalog\Domain\Product\ProductVariant;
use App\Catalog\Domain\Product\ProductVariants;
use App\Catalog\Domain\Product\ShortDescription;
use App\Catalog\Domain\Product\Sku;
use App\Catalog\Domain\Product\Slug;
use App\Catalog\Domain\Product\TaxClassCode;
use App\Catalog\Domain\Product\VariantAxes;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantValues;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SaveProductWithoutTheSkuCheckHandler
{
    public function __construct(private ProductRepository $products)
    {
    }

    public function __invoke(SaveProductWithoutTheSkuCheck $message): void
    {
        $now = new \DateTimeImmutable();
        $variants = [];
        foreach ($message->skus as $index => $sku) {
            $variants[] = new ProductVariant(VariantId::fromString(Uuid::v7()->toRfc4122()), VariantValues::fromList(['W'.$index]), Sku::optional($sku), Ean::optional(''));
        }

        $this->products->save(
            Product::create(
                ProductId::fromString($message->productId),
                ProductName::of('Produkt '.$message->slug),
                new Slug($message->slug),
                ShortDescription::of(''),
                ProductBrand::of(''),
                ProductDescription::of(''),
                ProductParameters::create([]),
                ProductCategories::create(CategoryId::fromString($message->categoryId)),
                TaxClassCode::Standard,
                ProductImages::fromList([]),
                ProductVariants::create(VariantAxes::fromList(['Rozmiar']), $variants),
                ProductStatus::Draft,
                $now,
            ),
            null,
            'staff-sub-1',
            $now,
        );
    }
}
