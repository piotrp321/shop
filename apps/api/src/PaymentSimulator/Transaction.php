<?php

declare(strict_types=1);

namespace App\PaymentSimulator;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A payment session of a fake provider. The decision of the customer (pay, reject) and the cancellation by the shop
 * are both made under the row lock of the transaction (`SELECT … FOR UPDATE` through the ORM), so they happen one
 * after the other and a transaction ends in exactly one final state. `decided_at` is the time of the payment that
 * the webhook reports (`paidAt` / `completedAt`). demo-transfer has one return URL, demo-card a success and a cancel
 * URL (the migration checks the shape).
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_simulator_transaction')]
class Transaction
{
    #[ORM\Column(length: 16, enumType: TransactionStatus::class)]
    public private(set) TransactionStatus $status = TransactionStatus::Open;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $decidedAt = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'guid')]
        public private(set) string $id,
        #[ORM\Column(length: 16, enumType: Provider::class)]
        public private(set) Provider $provider,
        #[ORM\Column(length: 100)]
        public private(set) string $reference,
        #[ORM\Column(type: Types::BIGINT)]
        public private(set) int $amount,
        #[ORM\Column(length: 3, options: ['fixed' => true])]
        public private(set) string $currency,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $returnUrl,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $successUrl,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $cancelUrl,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public private(set) \DateTimeImmutable $expiresAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public private(set) \DateTimeImmutable $createdAt,
    ) {
    }

    public static function openTransfer(string $id, string $reference, int $amount, string $currency, string $returnUrl, \DateTimeImmutable $expiresAt, \DateTimeImmutable $createdAt): self
    {
        return new self($id, Provider::DemoTransfer, $reference, $amount, $currency, $returnUrl, null, null, $expiresAt, $createdAt);
    }

    public static function openCard(string $id, string $reference, int $amount, string $currency, string $successUrl, string $cancelUrl, \DateTimeImmutable $expiresAt, \DateTimeImmutable $createdAt): self
    {
        return new self($id, Provider::DemoCard, $reference, $amount, $currency, null, $successUrl, $cancelUrl, $expiresAt, $createdAt);
    }

    /**
     * Pays or rejects an open transaction before its expiry; returns whether it did. Anything else changes nothing.
     */
    public function decide(bool $pay, \DateTimeImmutable $now): bool
    {
        if ($this->isClosed($now)) {
            return false;
        }
        $this->status = $pay ? TransactionStatus::Paid : TransactionStatus::Rejected;
        $this->decidedAt = $now;

        return true;
    }

    /**
     * Cancels an open transaction (also an expired one); a cancelled one stays cancelled and succeeds again.
     */
    public function cancel(): CancelResult
    {
        return match ($this->status) {
            TransactionStatus::Open, TransactionStatus::Cancelled => $this->cancelled(),
            TransactionStatus::Paid => CancelResult::Paid,
            TransactionStatus::Rejected => CancelResult::Rejected,
        };
    }

    /** Decided, cancelled or past its expiry: the payment page offers nothing any more. */
    public function isClosed(\DateTimeImmutable $now): bool
    {
        return TransactionStatus::Open !== $this->status || $now >= $this->expiresAt;
    }

    /**
     * Where the customer goes back to the shop. demo-card: the success URL after a payment, the cancel URL otherwise
     * (also without a decision).
     */
    public function returnUrl(): string
    {
        if (Provider::DemoTransfer === $this->provider) {
            return $this->returnUrl ?? throw new \LogicException('A demo-transfer transaction has a return URL.');
        }

        return (TransactionStatus::Paid === $this->status ? $this->successUrl : $this->cancelUrl)
            ?? throw new \LogicException('A demo-card transaction has a success and a cancel URL.');
    }

    private function cancelled(): CancelResult
    {
        $this->status = TransactionStatus::Cancelled;

        return CancelResult::Cancelled;
    }
}
