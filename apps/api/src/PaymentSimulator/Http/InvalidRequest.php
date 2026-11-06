<?php

declare(strict_types=1);

namespace App\PaymentSimulator\Http;

/**
 * A request body the fake provider does not accept; carries the name of the field.
 */
final class InvalidRequest extends \RuntimeException
{
    public function __construct(public readonly string $field)
    {
        parent::__construct('Invalid field: '.$field);
    }
}
