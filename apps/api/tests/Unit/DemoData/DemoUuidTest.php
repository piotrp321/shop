<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Identity\DemoUuid;
use App\DemoData\Random\Prng;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class DemoUuidTest extends TestCase
{
    private const string NAMESPACE_ID = '8d3b6f1e-5a27-4c90-b1e4-7f02a96c35d8';

    public function testV5IsTheNameBasedUuidOfTheNamespace(): void
    {
        $id = DemoUuid::v5(self::NAMESPACE_ID, 'product:17');

        self::assertSame(Uuid::v5(Uuid::fromString(self::NAMESPACE_ID), 'product:17')->toRfc4122(), $id);
        self::assertSame($id, DemoUuid::v5(self::NAMESPACE_ID, 'product:17'));
        self::assertNotSame($id, DemoUuid::v5(self::NAMESPACE_ID, 'product:18'));
        self::assertNotSame($id, DemoUuid::v5('0f0f0f0f-0000-4000-8000-000000000001', 'product:17'));
        self::assertInstanceOf(\Symfony\Component\Uid\UuidV5::class, Uuid::fromString($id));
    }

    public function testV5RejectsANamespaceThatIsNotAUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DemoUuid::v5('nope', 'product:1');
    }

    public function testV7CarriesTheEventTimeVersionAndVariant(): void
    {
        $prng = Prng::forPart(self::NAMESPACE_ID, 'orders', 0);
        $at = new \DateTimeImmutable('2026-03-04 05:06:07.123', new \DateTimeZone('UTC'));

        $id = DemoUuid::v7($at, $prng);

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id);
        $uuid = Uuid::fromString($id);
        self::assertInstanceOf(\Symfony\Component\Uid\UuidV7::class, $uuid);
        self::assertSame($at->format('Y-m-d\TH:i:s.v'), $uuid->getDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v'));
    }

    public function testV7IsDeterministicForTheSameTimeAndStream(): void
    {
        $at = new \DateTimeImmutable('2026-03-04 05:06:07', new \DateTimeZone('UTC'));

        $first = DemoUuid::v7($at, Prng::forPart(self::NAMESPACE_ID, 'orders', 2));
        $second = DemoUuid::v7($at, Prng::forPart(self::NAMESPACE_ID, 'orders', 2));

        self::assertSame($first, $second);
    }

    public function testV7DiffersForTheSameTimeWhenTheStreamAdvances(): void
    {
        $prng = Prng::forPart(self::NAMESPACE_ID, 'orders', 0);
        $at = new \DateTimeImmutable('2026-03-04 05:06:07', new \DateTimeZone('UTC'));

        self::assertNotSame(DemoUuid::v7($at, $prng), DemoUuid::v7($at, $prng));
    }

    public function testV7SortsByTime(): void
    {
        $prng = Prng::forPart(self::NAMESPACE_ID, 'orders', 0);
        $ids = [];
        for ($i = 0; $i < 50; ++$i) {
            $ids[] = DemoUuid::v7(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))->modify('+'.($i * 7).' seconds'), $prng);
        }
        $sorted = $ids;
        sort($sorted, \SORT_STRING);

        self::assertSame($ids, $sorted);
    }

    public function testV7RejectsATimeOutsideTheRangeOf48Bits(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DemoUuid::v7(new \DateTimeImmutable('1969-12-31 00:00:00', new \DateTimeZone('UTC')), Prng::forPart(self::NAMESPACE_ID, 'orders', 0));
    }
}
