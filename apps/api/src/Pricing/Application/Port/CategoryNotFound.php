<?php

declare(strict_types=1);

namespace App\Pricing\Application\Port;

use App\Shared\Domain\Exception\NotFound;

final class CategoryNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Category not found.');
    }
}
