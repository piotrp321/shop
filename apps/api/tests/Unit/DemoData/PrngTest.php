<?php

declare(strict_types=1);

namespace App\Tests\Unit\DemoData;

use App\DemoData\Random\Prng;
use PHPUnit\Framework\TestCase;

final class PrngTest extends TestCase
{
    private const string NAMESPACE_ID = '8d3b6f1e-5a27-4c90-b1e4-7f02a96c35d8';

    public function testTheSameSeedStreamAndPartGiveTheSameSequence(): void
    {
        $a = Prng::forPart(self::NAMESPACE_ID, 'catalog', 3);
        $b = Prng::forPart(self::NAMESPACE_ID, 'catalog', 3);

        self::assertSame($this->draw($a, 50), $this->draw($b, 50));
    }

    public function testAnotherPartAnotherStreamOrAnotherSeedGivesAnotherSequence(): void
    {
        $base = $this->draw(Prng::forPart(self::NAMESPACE_ID, 'catalog', 3), 20);

        self::assertNotSame($base, $this->draw(Prng::forPart(self::NAMESPACE_ID, 'catalog', 4), 20));
        self::assertNotSame($base, $this->draw(Prng::forPart(self::NAMESPACE_ID, 'orders', 3), 20));
        self::assertNotSame($base, $this->draw(Prng::forPart('0f0f0f0f-0000-4000-8000-000000000001', 'catalog', 3), 20));
    }

    public function testTheSequenceIsPinned(): void
    {
        // Characterization: a change of the engine or of the derivation of the seed changes every generated row, so it
        // has to be deliberate.
        self::assertSame(
            [1_440_955_764, 1_369_105_024, 1_797_134_217, 234_508_085, 899_757_523],
            $this->draw(Prng::forPart(self::NAMESPACE_ID, 'catalog', 0), 5, 0, 2_147_483_647),
        );
    }

    public function testTheGlobalGeneratorIsNotUsed(): void
    {
        mt_srand(1);
        $first = $this->draw(Prng::forPart(self::NAMESPACE_ID, 'catalog', 1), 10);
        mt_srand(2);
        $second = $this->draw(Prng::forPart(self::NAMESPACE_ID, 'catalog', 1), 10);

        self::assertSame($first, $second);
    }

    public function testIntStaysInTheClosedRange(): void
    {
        $prng = Prng::forPart(self::NAMESPACE_ID, 'catalog', 0);
        $seen = [];
        for ($i = 0; $i < 500; ++$i) {
            $value = $prng->int(3, 6);
            self::assertGreaterThanOrEqual(3, $value);
            self::assertLessThanOrEqual(6, $value);
            $seen[$value] = true;
        }
        self::assertCount(4, $seen);
    }

    public function testChanceFollowsTheProbability(): void
    {
        $prng = Prng::forPart(self::NAMESPACE_ID, 'catalog', 0);
        $hits = 0;
        for ($i = 0; $i < 10_000; ++$i) {
            $hits += $prng->chance(0.25) ? 1 : 0;
        }

        self::assertEqualsWithDelta(2500, $hits, 200);
        self::assertFalse($prng->chance(0.0));
        self::assertTrue($prng->chance(1.0));
    }

    public function testWeightedPickFollowsTheWeightsAndSkipsZeroWeights(): void
    {
        $prng = Prng::forPart(self::NAMESPACE_ID, 'catalog', 0);
        $counts = [0, 0, 0];
        for ($i = 0; $i < 10_000; ++$i) {
            ++$counts[$prng->weighted([1, 3, 0])];
        }

        self::assertSame(0, $counts[2]);
        self::assertEqualsWithDelta(2500, $counts[0], 250);
        self::assertEqualsWithDelta(7500, $counts[1], 250);
    }

    public function testPickAndShuffleUseTheStream(): void
    {
        $a = Prng::forPart(self::NAMESPACE_ID, 'catalog', 0);
        $b = Prng::forPart(self::NAMESPACE_ID, 'catalog', 0);
        $list = ['a', 'b', 'c', 'd', 'e', 'f'];

        self::assertSame($a->pick($list), $b->pick($list));
        $shuffled = $a->shuffle($list);
        self::assertSame($shuffled, $b->shuffle($list));
        $sorted = $shuffled;
        sort($sorted);
        self::assertSame($list, $sorted);
    }

    public function testRejectsAnInvalidSeed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Prng::forPart('not-a-uuid', 'catalog', 0);
    }

    /**
     * @return list<int>
     */
    private function draw(Prng $prng, int $count, int $min = 0, int $max = 1_000_000): array
    {
        $values = [];
        for ($i = 0; $i < $count; ++$i) {
            $values[] = $prng->int($min, $max);
        }

        return $values;
    }
}
