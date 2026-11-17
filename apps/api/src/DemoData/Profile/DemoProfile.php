<?php

declare(strict_types=1);

namespace App\DemoData\Profile;

/**
 * The counts of one profile (docs/features/demo-data/spec.md, "Profile"); diskPeakGb is the estimated peak of the disk
 * use of the whole stack, the base of the free space check of the k3d target.
 */
final readonly class DemoProfile
{
    public function __construct(
        public string $name,
        public int $staff,
        public int $customers,
        public int $products,
        public int $orders,
        public int $diskPeakGb,
    ) {
    }

    public function withProducts(int $products): self
    {
        return new self($this->name, $this->staff, $this->customers, $products, $this->orders, $this->diskPeakGb);
    }
}
