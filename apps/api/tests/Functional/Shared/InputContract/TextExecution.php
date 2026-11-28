<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\InputContract;

/**
 * One execution of a row of the contract of texts (spec: docs/features/api-input-contract/spec.md, "Test kontraktu
 * tekstów"), run as a test of its own: the value sent in the field of the row and what the answer has to be.
 */
final readonly class TextExecution
{
    public const string EMPTY = 'empty';
    public const string FORBIDDEN = 'forbidden';
    public const string ALLOWED = 'allowed in the field';
    public const string EDGES = 'edges';
    public const string PRESERVED = 'preserved';
    public const string WRONG_TYPE = 'wrong type';
    public const string FORMAT = 'format';

    /**
     * @param self::*     $class
     * @param mixed       $value       the value of the field (a phrase: the value of `q`; the edges of a phrase: the clean
     *                                 phrase)
     * @param int|null    $status      the exact status, null for any 2xx
     * @param string|null $title       the title of the one violation at the field of a 4xx
     * @param bool        $readsStored whether a 2xx is followed by the read of the stored value
     * @param mixed       $stored      the value the read has to give
     */
    public function __construct(
        public string $class,
        public string $label,
        public mixed $value,
        public ?int $status,
        public ?string $title,
        public bool $readsStored = false,
        public mixed $stored = null,
    ) {
    }

    /**
     * A 4xx with exactly one violation at the field of the row.
     *
     * @param self::* $class
     */
    public static function refused(string $class, string $label, mixed $value, int $status, string $title): self
    {
        return new self($class, $label, $value, $status, $title);
    }

    /**
     * A 2xx, followed by the read of the stored value when the row has a way to read it.
     *
     * @param self::* $class
     */
    public static function accepted(string $class, string $label, mixed $value, bool $readsStored, mixed $stored, ?int $status = null): self
    {
        return new self($class, $label, $value, $status, null, $readsStored, $stored);
    }

    public function isRefused(): bool
    {
        return null !== $this->title;
    }

    public function name(): string
    {
        return $this->class.': '.$this->label;
    }
}
