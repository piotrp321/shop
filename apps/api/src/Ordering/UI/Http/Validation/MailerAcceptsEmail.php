<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The email address is one the Symfony Mailer accepts. The confirmation of an order is sent through it, and it refuses
 * addresses that the HTML5 rule of Assert\Email lets through (`a..b@example.com`, `.a@example.com`, `a.@example.com`).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class MailerAcceptsEmail extends Constraint
{
    public function __construct(public string $message = 'Podaj poprawny adres e-mail.', ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }
}
