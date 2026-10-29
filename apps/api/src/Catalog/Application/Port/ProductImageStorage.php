<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

use App\Shared\Domain\Exception\InvalidInput;

interface ProductImageStorage
{
    /**
     * Validates and stores an original image.
     *
     * @return string the ImageKey: originals/<sha256>.<extension of the detected type>
     *
     * @throws InvalidInput when the file is too large, of an unsupported type or of too high a resolution
     */
    public function store(string $contents): string;

    /**
     * Removes every stored original. Only for tests and local seeding.
     */
    public function purge(): void;
}
