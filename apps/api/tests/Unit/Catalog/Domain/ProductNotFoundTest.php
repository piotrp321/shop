<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Domain;

use App\Catalog\Domain\Product\ProductNotFound;
use App\Shared\Domain\Exception\NotFound;
use PHPUnit\Framework\TestCase;

final class ProductNotFoundTest extends TestCase
{
    public function testIsNotFoundProblemWithFixedMessage(): void
    {
        $e = $this->create();

        self::assertInstanceOf(NotFound::class, $e);
        self::assertSame('Product not found.', $e->getMessage());
    }

    private function create(): \Throwable
    {
        return new ProductNotFound();
    }
}
