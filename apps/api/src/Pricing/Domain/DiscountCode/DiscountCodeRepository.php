<?php

declare(strict_types=1);

namespace App\Pricing\Domain\DiscountCode;

interface DiscountCodeRepository
{
    /**
     * The code as stored now (never as an earlier read of the same unit of work saw it), or null.
     */
    public function find(DiscountCodeId $id): ?DiscountCode;

    /**
     * The code with this text, as stored now (never as an earlier read of the same unit of work saw it), or null.
     */
    public function findByCode(DiscountCodeValue $code): ?DiscountCode;

    /**
     * The code read anew under the lock of its row until the end of the transaction, or null. The lock excludes another
     * lock of the code and its change, but not the usages written meanwhile (a code without a limit is never locked by
     * a placement).
     * Only in a transaction.
     */
    public function lock(DiscountCodeId $id): ?DiscountCode;

    /**
     * Writes a new code (version 1) or a revised one (written at its version, checked against the stored one).
     *
     * @throws DiscountCodeTaken                when another code has the same text
     * @throws DiscountCodeModifiedConcurrently when the stored version is not the one the code was read with
     */
    public function save(DiscountCode $code): void;
}
