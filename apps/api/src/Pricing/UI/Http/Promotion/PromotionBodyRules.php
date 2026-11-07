<?php

declare(strict_types=1);

namespace App\Pricing\UI\Http\Promotion;

use App\Pricing\Domain\Promotion\Promotion;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The shape of the moments and the variants of a promotion body, shared by its POST and PUT. The rules of a promotion
 * (lengths, the percent, the period, the number of variants and their repetition) are the domain's: its refusal is
 * shown at the field it names (PromotionWriteFailures). The messages never contain the value.
 */
final class PromotionBodyRules
{
    public const string MOMENT = 'Podaj czas w formacie RFC 3339 ze strefą, np. 2026-11-10T12:00:00Z.';
    public const string VARIANT_LIST = 'Podaj listę identyfikatorów wariantów.';
    public const string VARIANT_ID = 'Identyfikator wariantu musi być UUID.';
    private const string UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D';

    public static function validate(ExecutionContextInterface $context, mixed $startsAt, mixed $endsAt, mixed $variantIds): void
    {
        if (null !== $startsAt && null === Rfc3339::parse($startsAt)) {
            $context->buildViolation(self::MOMENT)->atPath('startsAt')->addViolation();
        }
        // A missing value is the violation of NotNull of the body.
        if (null !== $endsAt && null === Rfc3339::parse($endsAt)) {
            $context->buildViolation(self::MOMENT)->atPath('endsAt')->addViolation();
        }

        if (null === $variantIds) {
            return;
        }
        if (!\is_array($variantIds) || !array_is_list($variantIds)) {
            $context->buildViolation(self::VARIANT_LIST)->atPath('variantIds')->addViolation();

            return;
        }
        // Past the limit the domain refuses the list anyway; its elements are not looked at.
        if (\count($variantIds) > Promotion::MAX_VARIANTS) {
            return;
        }
        foreach ($variantIds as $index => $variantId) {
            if (!\is_string($variantId) || 1 !== preg_match(self::UUID, $variantId)) {
                $context->buildViolation(self::VARIANT_ID)->atPath('variantIds['.$index.']')->addViolation();
            }
        }
    }
}
