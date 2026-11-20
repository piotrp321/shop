<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Profile\Profiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ProfilesTest extends TestCase
{
    private function real(): Profiles
    {
        return Profiles::fromFile(\dirname(__DIR__, 3).'/fixtures/demo-data/profiles.yaml');
    }

    public function testTheProfilesHaveTheCountsOfTheSpecification(): void
    {
        $profiles = $this->real();

        $expected = [
            'small' => [20, 1_000, 2_000, 5_000],
            'medium' => [50, 100_000, 1_000_000, 1_000_000],
            'server' => [500, 1_000_000, 10_000_000, 5_000_000],
            'full' => [500, 1_000_000, 10_000_000, 50_000_000],
        ];
        self::assertSame(array_keys($expected), $profiles->names());
        foreach ($expected as $name => [$staff, $customers, $products, $orders]) {
            $profile = $profiles->get($name);
            self::assertSame($name, $profile->name);
            self::assertSame([$staff, $customers, $products, $orders], [$profile->staff, $profile->customers, $profile->products, $profile->orders]);
        }
    }

    public function testTheDiskPeakEstimatesAreTheOnesOfTheSpecification(): void
    {
        $profiles = $this->real();

        // medium: measured (the peak of the VM against the first reading, 20.52 GB, revision aceeab48, 2026-11-19); server and
        // full: extrapolated from the sizes of that run, not measured (docs/features/demo-data/spec.md, "k3d").
        self::assertSame(21, $profiles->get('medium')->diskPeakGb);
        self::assertSame(96, $profiles->get('server')->diskPeakGb);
        self::assertSame(250, $profiles->get('full')->diskPeakGb);
        self::assertGreaterThan(0, $profiles->get('small')->diskPeakGb);
    }

    public function testOneNamespaceAndOnePartSizeServeEveryProfile(): void
    {
        $profiles = $this->real();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $profiles->namespace);
        self::assertGreaterThan(0, $profiles->partSize);
        // The small profile has to span several parts, or the process count of the tests would not matter.
        self::assertGreaterThanOrEqual(3, $profiles->partCount($profiles->get('small')->products));
    }

    public function testTheNamespaceIsNotTheOneOfTheSeedCatalog(): void
    {
        /** @var array{namespace: string} $seed */
        $seed = Yaml::parseFile(\dirname(__DIR__, 3).'/fixtures/catalog.yaml');

        self::assertNotSame($seed['namespace'], $this->real()->namespace);
    }

    public function testPartCountRoundsUp(): void
    {
        $profiles = $this->real();
        $size = $profiles->partSize;

        self::assertSame(0, $profiles->partCount(0));
        self::assertSame(1, $profiles->partCount(1));
        self::assertSame(1, $profiles->partCount($size));
        self::assertSame(2, $profiles->partCount($size + 1));
    }

    public function testAPartCoversAContiguousRangeAndTheLastOneIsShort(): void
    {
        $profiles = $this->real();
        $size = $profiles->partSize;
        $total = 2 * $size + 7;

        self::assertSame([0, $size], $profiles->partRange(0, $total));
        self::assertSame([$size, $size], $profiles->partRange(1, $total));
        self::assertSame([2 * $size, 7], $profiles->partRange(2, $total));
    }

    public function testAnUnknownProfileIsRejectedWithTheKnownNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('small, medium, server, full');

        $this->real()->get('huge');
    }

    public function testACountOverrideKeepsTheRestOfTheProfile(): void
    {
        $profile = $this->real()->get('small')->withProducts(123);

        self::assertSame(123, $profile->products);
        self::assertSame(1_000, $profile->customers);
    }

    public function testAFileWithoutANamespaceIsRejected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'profiles');
        self::assertIsString($file);
        file_put_contents($file, "partSize: 10\nprofiles:\n  small: {staff: 1, customers: 1, products: 1, orders: 1, diskPeakGb: 1}\n");

        try {
            $this->expectException(\InvalidArgumentException::class);
            Profiles::fromFile($file);
        } finally {
            unlink($file);
        }
    }

    public function testAMissingFileIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Profiles::fromFile('/nonexistent/profiles.yaml');
    }
}
