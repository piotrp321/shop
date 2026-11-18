<?php

declare(strict_types=1);

namespace App\DemoData\Orders;

/**
 * A generated order: the row of ordering_order with its lines, its history, its payment and the usage of its code. The
 * guest access token and the idempotency keys are random (spec: "Model zagrożeń"); everything else is a function of the
 * seed, the anchor and the global index.
 */
final readonly class OrderSpec
{
    /**
     * @param 'customer'|'guest'     $ownerType
     * @param list<OrderLineSpec>    $lines     in their positions
     * @param list<StatusChangeSpec> $history   oldest first
     */
    public function __construct(
        public int $index,
        public string $id,
        public string $number,
        public string $status,
        public string $ownerType,
        public ?string $customerId,
        public string $email,
        public string $fullName,
        public string $phone,
        public string $street,
        public string $postalCode,
        public string $city,
        public int $totalNet,
        public int $totalVat,
        public int $totalGross,
        public string $idempotencyKey,
        public string $requestFingerprint,
        public \DateTimeImmutable $placedAt,
        public \DateTimeImmutable $paymentDeadline,
        public ?string $accessToken,
        public ?\DateTimeImmutable $paidAt,
        public ?string $paymentId,
        public ?\DateTimeImmutable $cancelledAt,
        public ?string $cancellationReason,
        public ?\DateTimeImmutable $shippedAt,
        public ?\DateTimeImmutable $deliveredAt,
        public ?\DateTimeImmutable $returnedAt,
        public ?int $refundAmount,
        public ?\DateTimeImmutable $refundDueAt,
        public ?\DateTimeImmutable $refundCompletedAt,
        public ?string $refundCompletedById,
        public ?string $refundCompletedByUsername,
        public ?string $discountCode,
        public ?string $discountKind,
        public ?int $discountValue,
        public ?int $discountGross,
        public int $version,
        public array $lines,
        public array $history,
        public ?PaymentSpec $payment,
        public ?CodeUsageSpec $codeUsage,
    ) {
    }
}
