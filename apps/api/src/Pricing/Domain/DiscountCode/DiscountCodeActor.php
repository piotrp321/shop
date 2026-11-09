<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

/**
 * The staff member who created or changed a discount code: the `sub` and the name from the backoffice token. A change
 * of the system (the demo data) has no actor (null).
 */
final readonly class DiscountCodeActor
{
    private const int MAX_LENGTH = 255;

    private function __construct(private string $id, private string $name)
    {
    }

    public static function staff(string $id, string $name): self
    {
        if ('' === $id || \strlen($id) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('A staff actor needs the id, at most 255 bytes.');
        }
        if ('' === trim($name) || mb_strlen($name) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('A staff actor needs the name, at most 255 characters.');
        }

        return new self($id, $name);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }
}
