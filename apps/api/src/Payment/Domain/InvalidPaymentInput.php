<?php

declare(strict_types=1);

namespace App\Payment\Domain;

use App\Shared\Domain\Exception\InvalidInput;

/**
 * Input of a payment that breaks a rule of its value objects. The API checks the same rules first (with the same
 * messages); the domain keeps them anyway. The message never contains the submitted value.
 */
final class InvalidPaymentInput extends \DomainException implements InvalidInput
{
    public static function provider(): self
    {
        return new self('Nieznany sposób płatności.');
    }

    public static function idempotencyKey(): self
    {
        return new self('Nieprawidłowy klucz żądania.');
    }
}
