<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/v1/admin/products/{id}/publish and /unpublish. The version is taken as JSON gives it, never cast.
 */
#[OA\Schema(description: 'The version of the product the action is based on.', required: ['version'])]
final readonly class PublicationRequest
{
    public function __construct(
        #[OA\Property(type: 'integer', minimum: 1, description: 'The `version` of the product the action is based on; another current version is a 409.', example: 3)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: 'Podaj wersję produktu.'),
            new Assert\Type('integer', message: 'Podaj wersję produktu.'),
            new Assert\Positive(message: 'Podaj wersję produktu.'),
        ])]
        public mixed $version = null,
    ) {
    }
}
