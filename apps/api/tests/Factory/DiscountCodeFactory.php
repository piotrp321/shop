<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeActor;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\DiscountKind;
use App\Pricing\Domain\DiscountCode\DiscountValue;
use App\Pricing\Domain\DiscountCode\MinimumOrder;
use App\Pricing\Domain\DiscountCode\UsageLimit;
use App\Pricing\Domain\DiscountCode\ValidityPeriod;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\ObjectFactory;

/**
 * Builds discount codes through DiscountCode::create: by default an enabled 10 % code with a unique text, started a
 * day ago, without an end, minimum or limit, created by the system now. Moments are relative to now (`-1 day`) or
 * absolute; `endsAt` null is no end.
 *
 * @extends ObjectFactory<DiscountCode>
 */
final class DiscountCodeFactory extends ObjectFactory
{
    public static function class(): string
    {
        return DiscountCode::class;
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaults(): array
    {
        return [
            'id' => Uuid::v7()->toRfc4122(),
            'code' => 'T'.strtoupper(bin2hex(random_bytes(6))),
            'kind' => DiscountKind::Percent,
            'value' => 10,
            'minimumOrderGross' => null,
            'startsAt' => '-1 day',
            'endsAt' => null,
            'usageLimit' => null,
            'enabled' => true,
            'actor' => null,
            'createdAt' => 'now',
        ];
    }

    protected function initialize(): static
    {
        return $this->instantiateWith(self::instantiate(...));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private static function instantiate(array $attributes): DiscountCode
    {
        [
            'id' => $id, 'code' => $code, 'kind' => $kind, 'value' => $value, 'minimumOrderGross' => $minimum, 'startsAt' => $startsAt,
            'endsAt' => $endsAt, 'usageLimit' => $limit, 'enabled' => $enabled, 'actor' => $actor, 'createdAt' => $createdAt,
        ] = $attributes;
        \assert(\is_string($id) && \is_string($code) && $kind instanceof DiscountKind && \is_int($value) && (null === $minimum || \is_int($minimum)));
        \assert(\is_string($startsAt) && (null === $endsAt || \is_string($endsAt)) && (null === $limit || \is_int($limit)) && \is_bool($enabled));
        \assert((null === $actor || $actor instanceof DiscountCodeActor) && \is_string($createdAt));

        return DiscountCode::create(
            DiscountCodeId::fromString($id),
            DiscountCodeValue::of($code),
            DiscountValue::of($kind, $value),
            null === $minimum ? null : MinimumOrder::of($minimum),
            ValidityPeriod::of(new \DateTimeImmutable($startsAt), null === $endsAt ? null : new \DateTimeImmutable($endsAt)),
            null === $limit ? null : UsageLimit::of($limit),
            $enabled,
            $actor,
            new \DateTimeImmutable($createdAt),
        );
    }
}
