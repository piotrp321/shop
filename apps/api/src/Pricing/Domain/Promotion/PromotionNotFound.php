<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Shared\Domain\Exception\NotFound;

final class PromotionNotFound extends \DomainException implements NotFound
{
    public function __construct()
    {
        parent::__construct('Promocja nie istnieje.');
    }
}
