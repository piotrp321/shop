<?php

declare(strict_types=1);

namespace App\Delivery\UI\Http;

use App\Delivery\Domain\DeliveryMethodDescription;
use App\Delivery\Domain\DeliveryMethodName;
use App\Delivery\Domain\DeliveryPosition;
use App\Delivery\Domain\DeliveryPrice;
use App\Delivery\Domain\FreeDeliveryThreshold;
use App\Delivery\Domain\InvalidDeliveryMethod;
use App\Delivery\Domain\TrackingUrlTemplate;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The rules of the bodies of the delivery method writes (W1), each field with at most one Polish violation: the types
 * here, the values by the value objects of the domain (applied, not copied), whose message is shown at the field.
 */
final class DeliveryMethodBodyRules
{
    public const string NAME = 'Nazwa jest wymagana.';
    public const string DESCRIPTION_TYPE = 'Opis musi być tekstem.';
    public const string PRICE = 'Cena jest wymagana.';
    public const string PRICE_TYPE = 'Cena musi być liczbą całkowitą groszy.';
    public const string FREE_FROM_TYPE = 'Próg darmowej dostawy musi być liczbą całkowitą groszy.';
    public const string ACTIVE = 'Wskaż, czy metoda jest aktywna.';
    public const string POSITION = 'Kolejność musi być liczbą całkowitą od 0 do 999.';
    public const string TEMPLATE_TYPE = 'Link śledzenia musi być tekstem.';
    public const string VERSION = 'Wersja metody jest wymagana.';
    public const string VERSION_VALUE = 'Wersja metody musi być liczbą całkowitą od 1.';

    /** The value rules of the fields, run once the type of the value is right. */
    public const array NAME_VALUE = [self::class, 'name'];
    public const array DESCRIPTION_VALUE = [self::class, 'description'];
    public const array PRICE_VALUE = [self::class, 'price'];
    public const array FREE_FROM_VALUE = [self::class, 'freeFrom'];
    public const array POSITION_VALUE = [self::class, 'position'];
    public const array TEMPLATE_VALUE = [self::class, 'template'];

    public static function name(mixed $value, ExecutionContextInterface $context): void
    {
        if (\is_string($value)) {
            self::apply($context, static fn () => DeliveryMethodName::of($value));
        }
    }

    public static function description(mixed $value, ExecutionContextInterface $context): void
    {
        if (null === $value || \is_string($value)) {
            self::apply($context, static fn () => DeliveryMethodDescription::of($value));
        }
    }

    public static function price(mixed $value, ExecutionContextInterface $context): void
    {
        if (\is_int($value)) {
            self::apply($context, static fn () => DeliveryPrice::of($value));
        }
    }

    public static function freeFrom(mixed $value, ExecutionContextInterface $context): void
    {
        if (null === $value || \is_int($value)) {
            self::apply($context, static fn () => FreeDeliveryThreshold::optional($value));
        }
    }

    public static function position(mixed $value, ExecutionContextInterface $context): void
    {
        if (\is_int($value)) {
            self::apply($context, static fn () => DeliveryPosition::of($value));
        }
    }

    public static function template(mixed $value, ExecutionContextInterface $context): void
    {
        if (null === $value || \is_string($value)) {
            self::apply($context, static fn () => TrackingUrlTemplate::optional($value));
        }
    }

    /**
     * @param \Closure(): mixed $build the value object of the field; its refusal is the violation at the field
     */
    private static function apply(ExecutionContextInterface $context, \Closure $build): void
    {
        try {
            $build();
        } catch (InvalidDeliveryMethod $refused) {
            $context->buildViolation($refused->getMessage())->addViolation();
        }
    }
}
