<?php

declare(strict_types=1);

namespace App\Pricing\Application\Query;

use App\Pricing\Application\Port\Clock;
use App\Pricing\Domain\Basket\BasketLine;
use App\Pricing\Domain\Basket\BasketPrice;
use App\Pricing\Domain\Basket\BasketPromotion;
use App\Pricing\Domain\Basket\BasketQuote;
use App\Pricing\Domain\Basket\QuotedLine;
use App\Pricing\Domain\DiscountCode\DiscountCode;
use App\Pricing\Domain\DiscountCode\DiscountCodeRepository;
use App\Pricing\Domain\DiscountCode\DiscountCodeUsageRepository;
use App\Pricing\Domain\DiscountCode\DiscountCodeValue;
use App\Pricing\Domain\DiscountCode\InvalidDiscountCode;
use App\Pricing\Domain\Price\VariantId;
use App\Pricing\Domain\Promotion\PromotionId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class QuoteBasketHandler
{
    public function __construct(
        private CurrentEffectivePrices $effectivePrices,
        private DiscountCodeRepository $codes,
        private DiscountCodeUsageRepository $usages,
        private Clock $clock,
    ) {
    }

    /**
     * Checks the query before any read, then one read of the effective prices at now (the prices and the active
     * promotions), the code by its normalised text and, only for a code with a limit, the count of its active usages
     * (along the partial index). BasketQuote does the rest.
     *
     * @throws InvalidBasketQuery
     */
    public function __invoke(QuoteBasket $query): QuotedBasket
    {
        $lines = self::lines($query->lines);
        $text = self::codeText($query->discountCode);

        $now = $this->clock->now();
        $prices = array_map(
            static fn (CurrentEffectivePrice $price): BasketPrice => new BasketPrice(
                $price->effective,
                $price->catalog->taxClass(),
                null === $price->promotion ? null : new BasketPromotion(PromotionId::fromString($price->promotion->promotionId), $price->promotion->name, $price->promotion->percentOff),
            ),
            $this->effectivePrices->at(array_map(static fn (BasketLine $line): VariantId => $line->variantId(), $lines), $now),
        );

        if (null === $text) {
            return self::view(BasketQuote::of($lines, $prices, null, 0, $now), null);
        }
        $code = $this->code($text);
        if (null === $code) {
            return self::view(BasketQuote::withUnknownCode($lines, $prices), $text);
        }
        $activeUses = null === $code->usageLimit() ? 0 : $this->usages->activeUsesOf($code->id());

        return self::view(BasketQuote::of($lines, $prices, $code, $activeUses, $now), $text);
    }

    /**
     * @param list<QuoteBasketLine> $requested
     *
     * @return list<BasketLine>
     *
     * @throws InvalidBasketQuery
     */
    private static function lines(array $requested): array
    {
        if ([] === $requested || \count($requested) > QuoteBasket::MAX_LINES) {
            throw InvalidBasketQuery::lines();
        }
        $lines = [];
        foreach ($requested as $line) {
            try {
                $basketLine = BasketLine::of(VariantId::fromString($line->variantId), $line->quantity);
            } catch (\InvalidArgumentException) {
                throw InvalidBasketQuery::lines();
            }
            $key = $basketLine->variantId()->toString();
            if (isset($lines[$key])) {
                throw InvalidBasketQuery::lines();
            }
            $lines[$key] = $basketLine;
        }

        return array_values($lines);
    }

    /**
     * The text of the code as it is looked up and named: valid UTF-8 (bad bytes replaced), trimmed of Unicode spaces,
     * ASCII letters in upper case; null for no code or a blank one.
     *
     * @throws InvalidBasketQuery
     */
    private static function codeText(?string $code): ?string
    {
        if (null === $code) {
            return null;
        }
        $valid = mb_scrub($code, 'UTF-8');
        if (mb_strlen($valid, 'UTF-8') > QuoteBasket::MAX_CODE_LENGTH) {
            throw InvalidBasketQuery::codeTooLong();
        }
        $text = strtoupper(DiscountCodeValue::trimmed($valid));

        return '' === $text ? null : $text;
    }

    /**
     * The code with the text, or null when no code has it (also a text that cannot be a code).
     */
    private function code(string $text): ?DiscountCode
    {
        try {
            return $this->codes->findByCode(DiscountCodeValue::of($text));
        } catch (InvalidDiscountCode) {
            return null;
        }
    }

    private static function view(BasketQuote $quote, ?string $text): QuotedBasket
    {
        $discount = $quote->discount();
        $rejection = $quote->rejection();

        return new QuotedBasket(
            array_map(static function (QuotedLine $line): QuotedBasketLine {
                $unit = $line->unit();
                $promotion = $line->promotion();

                return new QuotedBasketLine(
                    $line->variantId()->toString(),
                    $line->quantity(),
                    new QuotedUnitPrice($unit->net()->amount(), $unit->vat()->amount(), $unit->gross()->amount(), $unit->rate()->basisPoints(), $line->taxClass()->toString()),
                    $line->regularUnitGross()->amount(),
                    null === $promotion ? null : new QuotedLinePromotion($promotion->id()->toString(), $promotion->name(), $promotion->percentOff()->value()),
                    $line->grossBeforeDiscount()->amount(),
                    $line->discount()->amount(),
                    $line->amounts()->net()->amount(),
                    $line->amounts()->vat()->amount(),
                    $line->amounts()->gross()->amount(),
                );
            }, $quote->lines()),
            $quote->subtotalGross()->amount(),
            $quote->discountGross()->amount(),
            $quote->net()->amount(),
            $quote->vat()->amount(),
            $quote->gross()->amount(),
            $quote->gross()->currency()->value,
            null === $discount ? null : new QuotedDiscount(
                $discount->codeId()->toString(),
                $discount->code()->toString(),
                $discount->value()->kind()->value,
                $discount->value()->value(),
                $discount->amount()->amount(),
            ),
            null === $rejection ? null : new QuotedRejection(
                $text ?? throw new \LogicException('A rejection is of a code.'),
                $rejection->reason->value,
                $rejection->problemType(),
                $rejection->detail(),
                $rejection->minimumOrderGross,
            ),
        );
    }
}
