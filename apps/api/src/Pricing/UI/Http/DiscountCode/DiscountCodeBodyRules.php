<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\DiscountCode;

use App\Pricing\Domain\DiscountCode\InvalidDiscountCode;
use App\Pricing\UI\Http\Promotion\PromotionBodyRules;
use App\Pricing\UI\Http\Promotion\Rfc3339;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The shape of the values of a discount code body, shared by its POST and PUT: the types (taken as JSON gives them,
 * never cast) and the moments. The rules of a code (the text, the ranges, the minimum of an amount code, the period,
 * the edit rules) are the domain's: its refusal is shown at the field it names (DiscountCodeWriteFailures). The
 * messages are Polish and never contain the value.
 */
final class DiscountCodeBodyRules
{
    public const array KINDS = ['percent', 'amount'];
    public const string KIND = 'Rodzaj rabatu to percent albo amount.';
    public const string VALUE = 'Wartość rabatu musi być liczbą całkowitą.';
    public const string ENABLED = 'Włączenie kodu to true albo false.';
    public const string VERSION = 'Wersja musi być dodatnią liczbą całkowitą.';
    /** The type of an integer field is checked with the message of its range, which tells what the field takes. */
    public const string MINIMUM = 'Minimum to 0,01–1 000 000 zł.';
    public const string USAGE_LIMIT = 'Limit to 1–1 000 000 użyć.';
    public const string REQUIRED = 'To pole jest wymagane.';
    /** The first constraint of every key: a missing key has this violation only. */
    public const array KEY_GIVEN = [self::class, 'keyGiven'];

    public static function keyGiven(mixed $value, ExecutionContextInterface $context): void
    {
        if ($value instanceof MissingKey) {
            $context->addViolation(self::REQUIRED);
        }
    }

    public static function validate(ExecutionContextInterface $context, mixed $startsAt, mixed $endsAt): void
    {
        foreach (['startsAt' => $startsAt, InvalidDiscountCode::ENDS_AT => $endsAt] as $field => $value) {
            if ($value instanceof MissingKey) {
                $context->buildViolation(self::REQUIRED)->atPath($field)->addViolation();
            }
        }
        if (!$startsAt instanceof MissingKey && null === Rfc3339::parse($startsAt)) {
            $context->buildViolation(PromotionBodyRules::MOMENT)->atPath('startsAt')->addViolation();
        }
        if (null !== $endsAt && !$endsAt instanceof MissingKey && null === Rfc3339::parse($endsAt)) {
            $context->buildViolation(PromotionBodyRules::MOMENT)->atPath(InvalidDiscountCode::ENDS_AT)->addViolation();
        }
    }
}
