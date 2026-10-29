<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

interface ProductImageStorage
{
    /**
     * Validates and stores an original image.
     *
     * @return string the ImageKey: originals/<sha256>.<extension of the detected type>
     */
    public function store(string $contents): string;

    /**
     * Removes every stored original. Only for tests and local seeding.
     */
    public function purge(): void;
}
