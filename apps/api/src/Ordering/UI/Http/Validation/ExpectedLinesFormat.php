<?php

declare(strict_types=1);

namespace App\Ordering\UI\Http\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * `expectedLines` of an order request (docs/features/checkout/spec.md, "API"): a JSON list of 1 to 50 objects, each
 * with a `variantId` in the format of a UUID (any letter case) and a whole `quantity` of 1 to 99, each variant once.
 * The value is taken as JSON gives it, never cast. Only the first wrong element is named, so the field gives at most
 * one violation: on the list (not a list, empty, too long, a variant twice), on the element (not an object) or on its
 * field. `null` is left to NotNull.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class ExpectedLinesFormat extends Constraint
{
    public function __construct(public string $message = 'Nieprawidłowa lista pozycji zamówienia. Odśwież stronę.', ?array $groups = null, mixed $payload = null)
    {
        parent::__construct(null, $groups, $payload);
    }
}
