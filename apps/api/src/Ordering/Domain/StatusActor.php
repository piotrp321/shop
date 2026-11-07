<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The actor of an entry of the history of an order; only a staff member has an identity.
 */
final readonly class StatusActor
{
    private function __construct(
        private StatusActorType $type,
        private ?StaffActor $staff,
    ) {
    }

    public static function system(): self
    {
        return new self(StatusActorType::System, null);
    }

    public static function customer(): self
    {
        return new self(StatusActorType::Customer, null);
    }

    public static function staff(StaffActor $staff): self
    {
        return new self(StatusActorType::Staff, $staff);
    }

    public function type(): StatusActorType
    {
        return $this->type;
    }

    /**
     * The staff member of a staff change, null for the others.
     */
    public function staffMember(): ?StaffActor
    {
        return $this->staff;
    }
}
