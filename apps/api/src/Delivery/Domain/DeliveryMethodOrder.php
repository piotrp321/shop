<?php

declare(strict_types=1);

namespace App\Delivery\Domain;

/**
 * The one order of the methods, the same in the panel and in the quote (W1, W2): the active ones first by their
 * position, then by their name (Polish alphabetical order), then the inactive ones by their name. The id breaks the
 * remaining ties, so the order never depends on the order of the rows read.
 */
final class DeliveryMethodOrder
{
    /**
     * @param list<DeliveryMethod> $methods
     *
     * @return list<DeliveryMethod>
     */
    public static function sort(array $methods): array
    {
        $collator = new \Collator('pl_PL');
        usort($methods, static function (DeliveryMethod $a, DeliveryMethod $b) use ($collator): int {
            if ($a->isActive() !== $b->isActive()) {
                return $a->isActive() ? -1 : 1;
            }
            if ($a->isActive() && $a->position()->value() !== $b->position()->value()) {
                return $a->position()->value() <=> $b->position()->value();
            }

            return (int) $collator->compare($a->name()->toString(), $b->name()->toString())
                ?: strcmp($a->id()->toString(), $b->id()->toString());
        });

        return $methods;
    }
}
