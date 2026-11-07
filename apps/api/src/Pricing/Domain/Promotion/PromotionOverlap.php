<?php

declare(strict_types=1);

namespace App\Pricing\Domain\Promotion;

use App\Pricing\Domain\Price\VariantId;
use App\Shared\Domain\Exception\Conflict;
use App\Shared\Domain\Exception\ExtendedProblem;
use App\Shared\Domain\Exception\TypedProblem;

/**
 * Variants of the promotion are in another promotion whose period overlaps (decision Q4: a variant is in at most one
 * promotion at any moment). The problem lists them, so the panel can point at them.
 */
final class PromotionOverlap extends \DomainException implements Conflict, TypedProblem, ExtendedProblem
{
    public const string TYPE = 'tag:piotrp321.github.io,2026:shop/problems/promotion-overlap';

    /** @var list<string> */
    public readonly array $conflictingVariantIds;

    /**
     * @param list<VariantId> $conflictingVariantIds
     */
    public function __construct(array $conflictingVariantIds)
    {
        parent::__construct('Część wariantów jest już w innej promocji w tym okresie.');
        $this->conflictingVariantIds = array_map(static fn (VariantId $id): string => $id->toString(), $conflictingVariantIds);
    }

    public function problemType(): string
    {
        return self::TYPE;
    }

    public function problemExtensions(): array
    {
        return ['conflictingVariantIds' => $this->conflictingVariantIds];
    }
}
