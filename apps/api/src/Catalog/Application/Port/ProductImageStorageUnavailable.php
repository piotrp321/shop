<?php

declare(strict_types=1);

namespace App\Catalog\Application\Port;

/**
 * The image storage (Garage) cannot be reached: a transient failure of the infrastructure, not a rejected file.
 */
final class ProductImageStorageUnavailable extends \RuntimeException
{
    public static function because(\Throwable $previous): self
    {
        return new self('The image storage is unavailable.', 0, $previous);
    }
}
