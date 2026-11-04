<?php

declare(strict_types=1);

namespace App\Cart\Domain;

use App\Shared\Domain\Exception\InvalidInput;

final class InvalidQuantity extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('Ilość musi wynosić od 1 do 99 szt.');
    }
}
