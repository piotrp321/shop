<?php

declare(strict_types=1);

namespace App\DemoData\Accounts;

use App\DemoData\Random\Prng;

/**
 * A list of names with their weights (the number of people with the name), drawn with the probability weight / sum by a
 * binary search over the running sums, so a register list of many thousands of names costs a few steps per draw.
 */
final readonly class WeightedNames
{
    /** @var list<int> the running sum of the weights up to each name, inclusive */
    private array $sums;
    public int $total;

    /**
     * @param non-empty-list<string> $names
     * @param non-empty-list<int>    $weights positive, one per name
     */
    public function __construct(private array $names, private array $weights)
    {
        $sums = [];
        $sum = 0;
        foreach ($weights as $weight) {
            $sum += $weight;
            $sums[] = $sum;
        }
        $this->sums = $sums;
        $this->total = $sum;
    }

    /**
     * @return non-empty-list<string>
     */
    public function names(): array
    {
        return $this->names;
    }

    /**
     * @return array<string, int> every name with its weight, in the order of the list
     */
    public function weighted(): array
    {
        return array_combine($this->names, $this->weights);
    }

    public function pick(Prng $prng): string
    {
        return $this->names[$this->pickIndex($prng)];
    }

    /**
     * The position of the drawn name in the list.
     */
    public function pickIndex(Prng $prng): int
    {
        $point = (int) floor($prng->float() * $this->total);
        $low = 0;
        $high = \count($this->sums) - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->sums[$middle] > $point) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $low;
    }
}
