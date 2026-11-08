<?php

declare(strict_types=1);

namespace App\Pricing\Application\Demo;

use App\Pricing\Application\Command\DiscountCodeTerms;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\InvalidDiscountCode;
use Symfony\Component\Uid\Uuid;

/**
 * The demo discount codes in the order of the file. Every code is built as the CreateDiscountCode handler builds it
 * (the same value objects and the aggregate, without saving anything), so a code the seed would be refused is refused
 * here, before anything is reset.
 */
final readonly class DemoDiscountCodeList
{
    /** A period further from the seed than this many days (about 30 years) is a typo. */
    public const int MAX_DAYS = 10_000;

    /** @var list<DemoDiscountCode> */
    private array $codes;

    /**
     * @param list<array{code: string, kind: DiscountKind, value: int, minimumOrderGross: int|null, startsInDays: int, endsInDays: int|null}> $codes in order
     *
     * @throws \InvalidArgumentException on an empty list, a repeated code, an offset out of range or a code the
     *                                   domain would refuse (format, value, minimum, period)
     */
    public function __construct(array $codes)
    {
        if ([] === $codes) {
            throw new \InvalidArgumentException('The demo discount codes must name at least one code.');
        }

        $list = [];
        $seen = [];
        foreach ($codes as ['code' => $text, 'kind' => $kind, 'value' => $value, 'minimumOrderGross' => $minimum, 'startsInDays' => $startsInDays, 'endsInDays' => $endsInDays]) {
            foreach (['startsInDays' => $startsInDays, 'endsInDays' => $endsInDays] as $field => $days) {
                if (null !== $days && abs($days) > self::MAX_DAYS) {
                    throw new \InvalidArgumentException(\sprintf('The %s of the demo discount code "%s" must be within %d days.', $field, $text, self::MAX_DAYS));
                }
            }

            try {
                $code = DiscountCodeValue::of($text);
                // Any fixed moment: only the order of the start and the end counts here; the seed fixes the real times.
                $origin = new \DateTimeImmutable('2026-01-01T00:00:00Z');
                $terms = DiscountCodeTerms::of(
                    $kind,
                    $value,
                    $minimum,
                    $origin->modify(\sprintf('%+d days', $startsInDays)),
                    null === $endsInDays ? null : $origin->modify(\sprintf('%+d days', $endsInDays)),
                    null,
                );
                DiscountCode::create(DiscountCodeId::fromString(Uuid::v7()->toRfc4122()), $code, $terms->value, $terms->minimumOrder, $terms->period, $terms->usageLimit, true, null, $origin);
            } catch (InvalidDiscountCode $e) {
                throw new \InvalidArgumentException(\sprintf('The demo discount code "%s" is invalid: %s', $text, $e->getMessage()), 0, $e);
            }

            if (isset($seen[$code->toString()])) {
                throw new \InvalidArgumentException(\sprintf('The demo discount code "%s" is listed twice.', $code->toString()));
            }
            $seen[$code->toString()] = true;
            $list[] = new DemoDiscountCode($code->toString(), $kind, $value, $minimum, $startsInDays, $endsInDays);
        }
        $this->codes = $list;
    }

    /**
     * @return list<DemoDiscountCode>
     */
    public function codes(): array
    {
        return $this->codes;
    }
}
