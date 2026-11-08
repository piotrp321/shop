<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Domain\DiscountCode\DiscountCodeId;
use App\Pricing\Domain\DiscountCode\DiscountCodeNotFound;
use App\Pricing\Domain\DiscountCode\DiscountCodeRepository;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetDiscountCodeHandler
{
    public function __construct(
        private DiscountCodeRepository $codes,
        private DiscountCodeUsageRepository $usages,
        private Clock $clock,
    ) {
    }

    /**
     * @throws DiscountCodeNotFound also for an id that is no UUID
     */
    public function __invoke(GetDiscountCode $query): DiscountCodeView
    {
        try {
            $id = DiscountCodeId::fromString($query->id);
        } catch (\InvalidArgumentException) {
            throw new DiscountCodeNotFound();
        }
        $code = $this->codes->find($id) ?? throw new DiscountCodeNotFound();

        return new DiscountCodeView(
            $code->id()->toString(),
            $code->code()->toString(),
            $code->value()->kind(),
            $code->value()->value(),
            $code->minimumOrder()?->gross(),
            $code->period()->startsAt(),
            $code->period()->endsAt(),
            $code->usageLimit()?->value(),
            $this->usages->activeUsesOf($id),
            $code->isEnabled(),
            $code->status($this->clock->now()),
            $code->version(),
        );
    }
}
