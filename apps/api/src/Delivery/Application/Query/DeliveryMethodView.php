<?php

declare(strict_types=1);

namespace App\Delivery\Application\Query;

use App\Delivery\Domain\DeliveryMethod;

/**
 * A delivery method as the panel shows and edits it. Amounts in grosze.
 */
final readonly class DeliveryMethodView
{
    /**
     * @param string      $description         empty without a description
     * @param int|null    $freeFromGross       null without a threshold
     * @param string|null $trackingUrlTemplate null without a template
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public int $priceGross,
        public ?int $freeFromGross,
        public bool $active,
        public int $position,
        public ?string $trackingUrlTemplate,
        public int $version,
    ) {
    }

    public static function of(DeliveryMethod $method): self
    {
        return new self(
            $method->id()->toString(),
            $method->name()->toString(),
            $method->description()->toString(),
            $method->price()->gross(),
            $method->freeFrom()?->gross(),
            $method->isActive(),
            $method->position()->value(),
            $method->trackingUrlTemplate()?->toString(),
            $method->version(),
        );
    }
}
