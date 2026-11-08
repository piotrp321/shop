<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Product;

use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * A published product needs an image (decision K2): a draft without images cannot be published, and a save cannot
 * remove the last image of a published product that has images.
 */
final class ProductNotPublishable extends \DomainException implements Conflict, TypedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/product-not-publishable';

    public static function withoutImages(): self
    {
        return new self('Dodaj co najmniej jedno zdjęcie przed publikacją.');
    }

    public static function lastImageRemoved(): self
    {
        return new self('Opublikowany produkt musi mieć co najmniej jedno zdjęcie.');
    }

    public function problemType(): string
    {
        return self::TYPE;
    }
}
