<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeModifiedConcurrently;
use App\Pricing\Domain\DiscountCode\DiscountCodeNotEditable;
use App\Pricing\Domain\DiscountCode\DiscountCodeNotFound;
use App\Pricing\Domain\DiscountCode\DiscountCodeRepository;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageRepository;
use App\Pricing\Domain\DiscountCode\InvalidDiscountCode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Changes a code in the transaction of command.bus, under the lock of its row: the clock and the number of active
 * usages are read after the lock, so a placement that locks the code (one with a limit) is either before the change
 * or after it, and a limit is never set below the usages. Then the values (422), the version the change is based on
 * (409) and the edit rules of the code at now.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReviseDiscountCodeHandler
{
    public function __construct(
        private DiscountCodeRepository $codes,
        private DiscountCodeUsageRepository $usages,
        private Clock $clock,
    ) {
    }

    /**
     * @throws DiscountCodeNotFound             also for an id that is no UUID
     * @throws InvalidDiscountCode
     * @throws DiscountCodeModifiedConcurrently
     * @throws DiscountCodeNotEditable
     */
    public function __invoke(ReviseDiscountCode $command): void
    {
        try {
            $id = DiscountCodeId::fromString($command->id);
        } catch (\InvalidArgumentException) {
            throw new DiscountCodeNotFound();
        }
        $code = $this->codes->lock($id) ?? throw new DiscountCodeNotFound();
        $now = $this->clock->now();
        $activeUses = $this->usages->activeUsesOf($id);

        $terms = DiscountCodeTerms::of($command->kind, $command->value, $command->minimumOrderGross, $command->startsAt, $command->endsAt, $command->usageLimit);
        if ($code->version() !== $command->expectedVersion) {
            throw new DiscountCodeModifiedConcurrently();
        }
        $code->revise(
            $terms->value,
            $terms->minimumOrder,
            $terms->period,
            $terms->usageLimit,
            $command->enabled,
            $activeUses,
            DiscountCodeTerms::actor($command->actorId, $command->actorName),
            $now,
        );

        $this->codes->save($code);
    }
}
