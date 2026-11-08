<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pricing;

use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeModifiedConcurrently;
use App\Pricing\Domain\DiscountCode\DiscountCodeRepository;
use App\Pricing\Domain\DiscountCode\DiscountCodeTaken;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;

/**
 * Keeps discount codes like the database does: find() and lock() return a copy, save() checks the version it was
 * read with and the unique text.
 */
final class InMemoryDiscountCodeRepository implements DiscountCodeRepository
{
    /** @var array<string, DiscountCode> */
    private array $codes = [];
    public int $writes = 0;

    public function __construct(private readonly PricingJournal $journal = new PricingJournal())
    {
    }

    public function find(DiscountCodeId $id): ?DiscountCode
    {
        $this->journal->add('find');

        return $this->stored($id);
    }

    public function findByCode(DiscountCodeValue $code): ?DiscountCode
    {
        foreach ($this->codes as $stored) {
            if ($stored->code()->equals($code)) {
                return self::copy($stored);
            }
        }

        return null;
    }

    public function lock(DiscountCodeId $id): ?DiscountCode
    {
        $this->journal->add('lock');

        return $this->stored($id);
    }

    public function save(DiscountCode $code): void
    {
        $stored = $this->codes[$code->id()->toString()] ?? null;
        if ($code->version() - 1 !== ($stored?->version() ?? 0)) {
            throw new DiscountCodeModifiedConcurrently();
        }
        foreach ($this->codes as $other) {
            if (!$other->id()->equals($code->id()) && $other->code()->equals($code->code())) {
                throw new DiscountCodeTaken();
            }
        }
        $this->journal->add('save');
        $this->codes[$code->id()->toString()] = self::copy($code);
        ++$this->writes;
    }

    /**
     * Stores a code as if it had been saved earlier.
     */
    public function store(DiscountCode $code): void
    {
        $this->codes[$code->id()->toString()] = self::copy($code);
    }

    private function stored(DiscountCodeId $id): ?DiscountCode
    {
        $stored = $this->codes[$id->toString()] ?? null;

        return null === $stored ? null : self::copy($stored);
    }

    private static function copy(DiscountCode $code): DiscountCode
    {
        return DiscountCode::reconstitute(
            $code->id(),
            $code->code(),
            $code->value(),
            $code->minimumOrder(),
            $code->period(),
            $code->usageLimit(),
            $code->isEnabled(),
            $code->createdAt(),
            $code->createdBy(),
            $code->updatedAt(),
            $code->updatedBy(),
            $code->version(),
        );
    }
}
