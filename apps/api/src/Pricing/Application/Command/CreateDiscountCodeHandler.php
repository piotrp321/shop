<?php

declare(strict_types=1);

namespace App\Pricing\Application\Command;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeRepository;
use App\Pricing\Domain\DiscountCode\DiscountCodeTaken;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\InvalidDiscountCode;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Creates a code in the transaction of command.bus. The unique text is the repository's (its index), so two codes of
 * the same text created at once end with one of them and DiscountCodeTaken.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateDiscountCodeHandler
{
    public function __construct(
        private DiscountCodeRepository $codes,
        private Clock $clock,
    ) {
    }

    /**
     * @throws InvalidDiscountCode
     * @throws DiscountCodeTaken
     */
    public function __invoke(CreateDiscountCode $command): void
    {
        $code = DiscountCodeValue::of($command->code);
        $terms = DiscountCodeTerms::of($command->kind, $command->value, $command->minimumOrderGross, $command->startsAt, $command->endsAt, $command->usageLimit);

        $this->codes->save(DiscountCode::create(
            DiscountCodeId::fromString($command->id),
            $code,
            $terms->value,
            $terms->minimumOrder,
            $terms->period,
            $terms->usageLimit,
            $command->enabled,
            DiscountCodeTerms::actor($command->actorId, $command->actorName),
            $this->clock->now(),
        ));
    }
}
