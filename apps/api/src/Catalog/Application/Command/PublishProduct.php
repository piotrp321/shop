<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

final readonly class PublishProduct
{
    /**
     * @param int    $expectedVersion the version the action is based on
     * @param string $actorId         the staff member (`sub`)
     */
    public function __construct(
        public string $productId,
        public int $expectedVersion,
        public string $actorId,
    ) {
    }
}
