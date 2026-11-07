<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The staff member who changed an order: the stable id (`sub` of the token of the `backoffice` realm) and the name
 * shown in the history.
 */
final readonly class StaffActor
{
    public const int MAX_LENGTH = 255;

    private function __construct(
        private string $staffId,
        private string $username,
    ) {
    }

    /**
     * @throws \InvalidArgumentException no id, an id over 255 bytes, no name or a name over 255 characters
     */
    public static function of(string $staffId, string $username): self
    {
        if ('' === $staffId || \strlen($staffId) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('A staff member needs an id of at most 255 bytes.');
        }
        if ('' === trim($username) || mb_strlen($username) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('A staff member needs a name of at most 255 characters.');
        }

        return new self($staffId, $username);
    }

    public function staffId(): string
    {
        return $this->staffId;
    }

    public function username(): string
    {
        return $this->username;
    }
}
