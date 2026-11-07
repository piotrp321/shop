<?php

declare(strict_types=1);

namespace App\Ordering\Application\Port;

/**
 * The result of OrderNotifications::claim(): the caller holds the sending now (claimed), the email went out before
 * (alreadySent), or another sender holds it until the end of its lease (inProgress).
 */
final readonly class NotificationClaim
{
    private function __construct(
        private bool $claimed,
        private bool $alreadySent,
        private ?\DateTimeImmutable $heldUntil,
    ) {
    }

    public static function claimed(): self
    {
        return new self(true, false, null);
    }

    public static function alreadySent(): self
    {
        return new self(false, true, null);
    }

    public static function inProgress(\DateTimeImmutable $until): self
    {
        return new self(false, false, $until);
    }

    public function isClaimed(): bool
    {
        return $this->claimed;
    }

    public function isAlreadySent(): bool
    {
        return $this->alreadySent;
    }

    /**
     * The end of the lease of the other sender; null unless in progress.
     */
    public function heldUntil(): ?\DateTimeImmutable
    {
        return $this->heldUntil;
    }
}
