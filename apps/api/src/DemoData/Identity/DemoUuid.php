<?php

declare(strict_types=1);

namespace App\DemoData\Identity;

use App\DemoData\Random\Prng;
use Symfony\Component\Uid\Uuid;

/**
 * The identifiers of the generated rows. Entities with a name (categories, products, variants, accounts, codes) get
 * UUID v5 of the namespace of the seed; events (orders, lines, payments, price history, reservations, movements) get
 * UUID v7 with the time of the event and the random bits from the stream of their part, so they grow with time like the
 * ones the application makes.
 */
final class DemoUuid
{
    private const int MAX_MILLISECONDS = 0xFFFFFFFFFFFF;

    private function __construct()
    {
    }

    /**
     * @throws \InvalidArgumentException when the namespace is not a UUID
     */
    public static function v5(string $namespace, string $name): string
    {
        if (!Uuid::isValid($namespace)) {
            throw new \InvalidArgumentException(\sprintf('The namespace "%s" is not a UUID.', $namespace));
        }

        return Uuid::v5(Uuid::fromString($namespace), $name)->toRfc4122();
    }

    /**
     * @throws \InvalidArgumentException when the time does not fit the 48 bits of milliseconds since the epoch
     */
    public static function v7(\DateTimeImmutable $at, Prng $prng): string
    {
        $milliseconds = (int) $at->format('Uv');
        if ($milliseconds < 0 || $milliseconds > self::MAX_MILLISECONDS) {
            throw new \InvalidArgumentException('The time of a UUID v7 has to fit 48 bits of milliseconds since the epoch.');
        }

        $bytes = substr(pack('J', $milliseconds), 2).$prng->bytes(10);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return Uuid::fromBinary($bytes)->toRfc4122();
    }
}
