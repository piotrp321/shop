<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeRejection;
use App\Pricing\Domain\DiscountCode\DiscountCodeRepository;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsage;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageId;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageRepository;
use App\Pricing\Domain\DiscountCode\OrderId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Records the usage of a code in the transaction of the placement (decisions D7, A3). The code is always read as
 * stored now; a code with a limit is read again under the lock of its row, so two placements for its last free usage
 * run one after another and the second one finds it used up. A code without a limit is not locked: placements with it
 * never wait for each other nor for a change of the code (the foreign key of the usage takes FOR KEY SHARE, which an
 * UPDATE of the code does not conflict with). The clock and the active usages are read after the lock; then the rules
 * of the code once more (rejectionFor). A rejection is thrown and never caught by the placement, so the whole
 * transaction rolls back and the unit of work writes nothing of it.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class RedeemDiscountCodeHandler
{
    public function __construct(
        private DiscountCodeRepository $codes,
        private DiscountCodeUsageRepository $usages,
        private Clock $clock,
    ) {
    }

    /**
     * @throws DiscountCodeRejected also for a code that does not exist
     */
    public function __invoke(RedeemDiscountCode $command): void
    {
        $id = DiscountCodeId::fromString($command->codeId);
        $code = $this->codes->find($id);
        if (null !== $code?->usageLimit()) {
            $code = $this->codes->lock($id);
        }
        if (null === $code) {
            throw new DiscountCodeRejected(DiscountCodeRejection::invalid());
        }
        $now = $this->clock->now();
        $activeUses = null === $code->usageLimit() ? 0 : $this->usages->activeUsesOf($id);

        $rejection = $code->rejectionFor($command->subtotalGross, $activeUses, $now);
        if (null !== $rejection) {
            throw new DiscountCodeRejected($rejection);
        }

        $this->usages->add(DiscountCodeUsage::record(DiscountCodeUsageId::fromString(Uuid::v7()->toRfc4122()), $id, OrderId::fromString($command->orderId), $now));
    }
}
