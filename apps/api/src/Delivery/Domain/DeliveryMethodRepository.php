<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

interface DeliveryMethodRepository
{
    /**
     * The method as stored now (never as an earlier read of the same unit of work saw it), or null.
     */
    public function find(DeliveryMethodId $id): ?DeliveryMethod;

    /**
     * Every method, active and inactive, in no particular order (DeliveryMethodOrder sorts them).
     *
     * @return list<DeliveryMethod>
     */
    public function all(): array;

    /**
     * The active methods, in no particular order.
     *
     * @return list<DeliveryMethod>
     */
    public function active(): array;

    public function countActive(): int;

    /**
     * Writes a new method (version 1) or a revised one (written at its version, checked against the stored one).
     *
     * @throws DeliveryMethodNameTaken            when another method has the same name without regard to case
     * @throws DeliveryMethodModifiedConcurrently when the stored version is not the one the method was read with
     */
    public function save(DeliveryMethod $method): void;
}
