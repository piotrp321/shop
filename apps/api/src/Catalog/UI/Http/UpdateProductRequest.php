<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use App\Catalog\UI\Http\Validation\ValidProductWrite;
use OpenApi\Attributes as OA;

/**
 * Body of PUT /api/v1/admin/products/{id}: the `version` the edit is based on and the whole data of the product. The values are taken as JSON gives them and validated by {@see ValidProductWrite}, never cast; a missing key is
 * a violation at its field. Unknown keys are ignored.
 */
#[OA\Schema(description: 'The whole data of the product and the version it is based on.', required: ['version', 'name', 'slug', 'shortDescription', 'mainCategoryId', 'additionalCategoryIds', 'taxClass', 'images', 'variantAxes', 'variants'])]
#[ValidProductWrite]
final readonly class UpdateProductRequest
{
    public function __construct(
        #[OA\Property(type: 'integer', minimum: 1, description: 'The `version` of the product the edit is based on; another current version is a 409.', example: 3)]
        public mixed $version = null,
        #[OA\Property(type: 'string', minLength: 1, maxLength: 200, description: 'Product name, 1 to 200 characters.', example: 'Buty biegowe Trail')]
        public mixed $name = null,
        #[OA\Property(type: 'string', maxLength: 200, description: 'Slug: lower-case letters without Polish diacritics, digits and single dashes. Empty: the API derives it from the name (or keeps it once the product was published). Changeable only until the first publication.', example: 'buty-biegowe-trail')]
        public mixed $slug = null,
        #[OA\Property(type: 'string', maxLength: 500, description: 'Short description, up to 500 characters; may be empty.')]
        public mixed $shortDescription = null,
        #[OA\Property(type: 'string', format: 'uuid', description: 'Id of the main category; an unknown category is a 422 at this field.')]
        public mixed $mainCategoryId = null,
        #[OA\Property(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), maxItems: 10, description: 'Ids of up to 10 additional categories, different from each other and from the main one.')]
        public mixed $additionalCategoryIds = null,
        #[OA\Property(type: 'string', description: 'Code of the tax class, one of `GET /tax-classes`.', example: 'standard')]
        public mixed $taxClass = null,
        #[OA\Property(type: 'array', maxItems: 10, description: 'Up to 10 images in presentation order (the first one is the main one), each an original stored by `POST /product-images`; a key is used once.', items: new OA\Items(type: 'object', required: ['key', 'alt'], properties: [
            new OA\Property(property: 'key', type: 'string', description: 'The `key` returned by the upload.', example: 'originals/0a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9.jpg'),
            new OA\Property(property: 'alt', type: 'string', minLength: 1, maxLength: 200, description: 'Alternative text.'),
        ]))]
        public mixed $images = null,
        #[OA\Property(type: 'array', maxItems: 2, items: new OA\Items(type: 'string', minLength: 1, maxLength: 30), description: 'Names of up to 2 variant axes, 1 to 30 characters, without a colon, different without regard to case.')]
        public mixed $variantAxes = null,
        #[OA\Property(type: 'array', minItems: 1, maxItems: 100, description: 'The variants in presentation order: one row per variant with one value per axis (1 to 40 characters); exactly one row without values for a product without axes. A row with the `variantId` of the product keeps that variant, a row with `null` is a new one. No two rows have the same values without regard to case and spaces at the ends.', items: new OA\Items(type: 'object', required: ['variantId', 'values'], properties: [
            new OA\Property(property: 'variantId', type: 'string', format: 'uuid', nullable: true),
            new OA\Property(property: 'values', type: 'array', items: new OA\Items(type: 'string', minLength: 1, maxLength: 40)),
        ]))]
        public mixed $variants = null,
    ) {
    }

    /**
     * Only after the body was validated.
     */
    public function data(): ProductWriteData
    {
        return ProductWriteData::of($this->name, $this->slug, $this->shortDescription, $this->mainCategoryId, $this->additionalCategoryIds, $this->taxClass, $this->images, $this->variantAxes, $this->variants);
    }
}
