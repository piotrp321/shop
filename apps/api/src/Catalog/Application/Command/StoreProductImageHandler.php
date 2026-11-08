<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Application\Image\ImageSize;
use App\Catalog\Application\Image\SizedImage;
use App\Catalog\Application\Port\ImageUrlGenerator;
use App\Catalog\Application\Port\ProductImageStorage;
use App\Catalog\Application\Port\ProductImageStorageUnavailable;
use App\Shared\Domain\Exception\InvalidInput;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class StoreProductImageHandler
{
    public function __construct(
        private ProductImageStorage $storage,
        private ImageUrlGenerator $imageUrls,
    ) {
    }

    /**
     * @throws InvalidInput                   when the file is too large, of an unsupported type or of too high a resolution
     * @throws ProductImageStorageUnavailable
     */
    public function __invoke(StoreProductImage $command): StoredProductImage
    {
        $key = $this->storage->store($command->contents);

        return new StoredProductImage($key, SizedImage::view($this->imageUrls, ImageSize::Thumbnail, $key, ''));
    }
}
