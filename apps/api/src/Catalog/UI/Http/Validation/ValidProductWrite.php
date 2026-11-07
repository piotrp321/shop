<?php

declare(strict_types=1);

namespace App\Catalog\UI\Http\Validation;

use Symfony\Component\Validator\Constraint;

/**
 * The body of a product write (create or edit) follows every rule of the product form, each violation at the path of
 * its field (`variants[3].values[1]`) with a Polish message. The values are taken as JSON gives them, never cast.
 * The rules of the domain are applied, not copied: the slug, the image key, the variant values and their comparison
 * key come from the domain classes.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidProductWrite extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
