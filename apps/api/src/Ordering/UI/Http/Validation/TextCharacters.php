<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The text has no character the rule of texts forbids (App\Shared\Domain\Text, T1), looked for in the value as it came:
 * the trim of the other constraints of the field would drop some of them at the ends. A value that is not a string is
 * left to the other constraints of the field.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class TextCharacters extends Constraint
{
    public function __construct(public string $message, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }
}
