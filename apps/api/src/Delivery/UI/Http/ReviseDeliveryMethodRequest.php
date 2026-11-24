<?php

declare(strict_types=1);

namespace App\Delivery\UI\Http;

use App\Delivery\Domain\DeliveryMethodDescription;
use App\Delivery\Domain\DeliveryMethodName;
use App\Delivery\Domain\DeliveryPosition;
use App\Delivery\Domain\DeliveryPrice;
use App\Delivery\Domain\FreeDeliveryThreshold;
use App\Delivery\Domain\TrackingUrlTemplate;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of PUT /api/v1/admin/delivery-methods/{id}: the whole method as the administrator wants it and the version it
 * is based on. The same rules as CreateDeliveryMethodRequest; every key is required (RequiredKeysBody).
 */
#[OA\Schema(description: 'A delivery method as it should be.', required: ['name', 'description', 'priceGross', 'freeFromGross', 'active', 'position', 'trackingUrlTemplate', 'version'])]
final readonly class ReviseDeliveryMethodRequest
{
    public function __construct(
        #[OA\Property(type: 'string', description: '1 to 100 characters once trimmed of white space and invisible characters at the ends, with a visible character, without control characters or line separators, unique among all methods without regard to case.', maxLength: DeliveryMethodName::MAX_LENGTH, example: 'Kurier standardowy')]
        #[Assert\Sequentially([
            new Assert\NotNull(message: DeliveryMethodBodyRules::NAME),
            new Assert\Type('string', message: DeliveryMethodBodyRules::NAME),
            new Assert\Callback(DeliveryMethodBodyRules::NAME_VALUE),
        ])]
        public mixed $name,
        #[OA\Property(type: 'string', description: 'The time of the delivery: up to 200 characters once trimmed, without control characters or line separators. Null or a text without a visible character: no description.', maxLength: DeliveryMethodDescription::MAX_LENGTH, nullable: true, example: 'Dostawa w 2–3 dni robocze')]
        #[Assert\Sequentially([
            new Assert\Type('string', message: DeliveryMethodBodyRules::DESCRIPTION_TYPE),
            new Assert\Callback(DeliveryMethodBodyRules::DESCRIPTION_VALUE),
        ])]
        public mixed $description,
        #[OA\Property(type: 'integer', description: 'Gross price in grosze.', maximum: DeliveryPrice::MAX, minimum: DeliveryPrice::MIN, example: 1999)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: DeliveryMethodBodyRules::PRICE),
            new Assert\Type('integer', message: DeliveryMethodBodyRules::PRICE_TYPE),
            new Assert\Callback(DeliveryMethodBodyRules::PRICE_VALUE),
        ])]
        public mixed $priceGross,
        #[OA\Property(type: 'integer', description: 'The method is free for goods (gross after discounts, grosze) of at least this value. Null: no threshold.', maximum: FreeDeliveryThreshold::MAX, minimum: FreeDeliveryThreshold::MIN, nullable: true, example: 29900)]
        #[Assert\Sequentially([
            new Assert\Type('integer', message: DeliveryMethodBodyRules::FREE_FROM_TYPE),
            new Assert\Callback(DeliveryMethodBodyRules::FREE_FROM_VALUE),
        ])]
        public mixed $freeFromGross,
        #[OA\Property(type: 'boolean', description: 'Offered in the cart and the checkout. At most 20 methods are active; activating one more is a 409.', example: true)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: DeliveryMethodBodyRules::ACTIVE),
            new Assert\Type('bool', message: DeliveryMethodBodyRules::ACTIVE),
        ])]
        public mixed $active,
        #[OA\Property(type: 'integer', description: 'Ascending position among the active methods; one position is ordered by name.', maximum: DeliveryPosition::MAX, minimum: DeliveryPosition::MIN, example: 10)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: DeliveryMethodBodyRules::POSITION),
            new Assert\Type('integer', message: DeliveryMethodBodyRules::POSITION),
            new Assert\Callback(DeliveryMethodBodyRules::POSITION_VALUE),
        ])]
        public mixed $position,
        #[OA\Property(type: 'string', description: 'The tracking address of the carrier: https:// with a host name, `{numer}` exactly once in place of the tracking number, without white space, up to 500 characters. Null or white space only: no template.', maxLength: TrackingUrlTemplate::MAX_LENGTH, nullable: true, example: 'https://tracking.example.com/{numer}')]
        #[Assert\Sequentially([
            new Assert\Type('string', message: DeliveryMethodBodyRules::TEMPLATE_TYPE),
            new Assert\Callback(DeliveryMethodBodyRules::TEMPLATE_VALUE),
        ])]
        public mixed $trackingUrlTemplate,
        #[OA\Property(type: 'integer', description: 'The `version` of the method the change is based on.', minimum: 1, example: 1)]
        #[Assert\Sequentially([
            new Assert\NotNull(message: DeliveryMethodBodyRules::VERSION),
            new Assert\Type('integer', message: DeliveryMethodBodyRules::VERSION_VALUE),
            new Assert\Positive(message: DeliveryMethodBodyRules::VERSION_VALUE),
        ])]
        public mixed $version,
    ) {
    }
}
