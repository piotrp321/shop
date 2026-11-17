<?php

declare(strict_types=1);

namespace App\DemoData\Random;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

/**
 * One deterministic stream of pseudo-random numbers: xoshiro256** seeded from the namespace of the seed, the name of
 * the stream and the number of the part (sha256 of the three), never from the global generator or the clock. A part
 * draws only from its own stream, so the rows of a part do not depend on the other parts or on how many processes
 * generate them.
 */
final class Prng
{
    private function __construct(private readonly Randomizer $randomizer)
    {
    }

    /**
     * @throws \InvalidArgumentException when the namespace is not a UUID
     */
    public static function forPart(string $namespace, string $stream, int $part): self
    {
        if (!Uuid::isValid($namespace)) {
            throw new \InvalidArgumentException(\sprintf('The seed "%s" is not a UUID.', $namespace));
        }
        $seed = hash('sha256', Uuid::fromString($namespace)->toRfc4122().'|'.$stream.'|'.$part, true);

        return new self(new Randomizer(new Xoshiro256StarStar($seed)));
    }

    /**
     * Uniform in the closed range.
     */
    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    /**
     * A float in [0, 1).
     */
    public function float(): float
    {
        return $this->randomizer->nextInt() / (\PHP_INT_MAX + 1.0);
    }

    public function chance(float $probability): bool
    {
        if ($probability <= 0.0) {
            return false;
        }
        if ($probability >= 1.0) {
            return true;
        }

        return $this->float() < $probability;
    }

    /**
     * The index of a weight, chosen with the probability weight / sum; a zero weight is never chosen.
     *
     * @param non-empty-list<int|float> $weights
     */
    public function weighted(array $weights): int
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            throw new \InvalidArgumentException('The weights need a positive sum.');
        }
        $point = $this->float() * $total;
        $last = 0;
        foreach ($weights as $index => $weight) {
            if ($weight <= 0) {
                continue;
            }
            $last = $index;
            $point -= $weight;
            if ($point < 0) {
                return $index;
            }
        }

        return $last;
    }

    /**
     * @template T
     *
     * @param non-empty-list<T> $list
     *
     * @return T
     */
    public function pick(array $list): mixed
    {
        return $list[$this->int(0, \count($list) - 1)];
    }

    /**
     * @template T
     *
     * @param list<T> $list
     *
     * @return list<T>
     */
    public function shuffle(array $list): array
    {
        return $this->randomizer->shuffleArray($list);
    }

    /**
     * @param positive-int $length
     */
    public function bytes(int $length): string
    {
        return $this->randomizer->getBytes($length);
    }
}
