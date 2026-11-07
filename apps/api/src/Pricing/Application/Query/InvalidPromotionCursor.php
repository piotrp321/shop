<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * The paging cursor of the promotion list is not one that was handed out. The message never contains the value.
 */
final class InvalidPromotionCursor extends \DomainException implements InvalidInput
{
    public function __construct()
    {
        parent::__construct('The paging cursor is invalid.');
    }
}
