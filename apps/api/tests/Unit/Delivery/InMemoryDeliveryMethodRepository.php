<?php

declare(strict_types=1);

namespace App\Tests\Unit\Delivery;

use App\Delivery\Domain\DeliveryMethod;
use App\Delivery\Domain\DeliveryMethodId;
use App\Delivery\Domain\DeliveryMethodModifiedConcurrently;
use App\Delivery\Domain\DeliveryMethodNameTaken;
use App\Delivery\Domain\DeliveryMethodRepository;

/**
 * Delivery methods in memory, in the order they were first saved. Like the table: a name is unique without regard to
 * case, and a revised method is written only at the version after the stored one. Every read gives a copy, so a
 * method changed and not saved is not seen.
 */
final class InMemoryDeliveryMethodRepository implements DeliveryMethodRepository
{
    /** @var array<string, DeliveryMethod> */
    private array $methods = [];

    public function add(DeliveryMethod ...$methods): void
    {
        foreach ($methods as $method) {
            $this->methods[$method->id()->toString()] = clone $method;
        }
    }

    public function find(DeliveryMethodId $id): ?DeliveryMethod
    {
        $method = $this->methods[$id->toString()] ?? null;

        return null === $method ? null : clone $method;
    }

    public function all(): array
    {
        return array_values(array_map(static fn (DeliveryMethod $method): DeliveryMethod => clone $method, $this->methods));
    }

    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn (DeliveryMethod $method): bool => $method->isActive()));
    }

    public function countActive(): int
    {
        return \count($this->active());
    }

    public function save(DeliveryMethod $method): void
    {
        $id = $method->id()->toString();
        $stored = $this->methods[$id] ?? null;
        if (null === $stored ? 1 !== $method->version() : $stored->version() !== $method->version() - 1) {
            throw new DeliveryMethodModifiedConcurrently();
        }
        foreach ($this->methods as $otherId => $other) {
            if ($otherId !== $id && mb_strtolower($other->name()->toString()) === mb_strtolower($method->name()->toString())) {
                throw new DeliveryMethodNameTaken();
            }
        }
        $this->methods[$id] = clone $method;
    }
}
