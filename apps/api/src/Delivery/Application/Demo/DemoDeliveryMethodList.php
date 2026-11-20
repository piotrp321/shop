<?php

declare(strict_types=1);

namespace App\Delivery\Application\Demo;

use App\Delivery\Application\Command\DeliveryMethodTerms;
use App\Delivery\Domain\DeliveryMethod;
use App\Delivery\Domain\InvalidDeliveryMethod;

/**
 * The demo delivery methods in the order of the file. Every method is checked with the value objects the
 * CreateDeliveryMethod handler builds, so a method the seed would be refused is refused here, before anything is
 * reset; so are a repeated name (without regard to case) and more methods than may be active.
 */
final readonly class DemoDeliveryMethodList
{
    /** @var list<DemoDeliveryMethod> */
    private array $methods;

    /**
     * @param list<DemoDeliveryMethod> $methods in order
     *
     * @throws \InvalidArgumentException on an empty list, a repeated name, too many methods or a method the domain
     *                                   would refuse
     */
    public function __construct(array $methods)
    {
        if ([] === $methods) {
            throw new \InvalidArgumentException('The demo delivery methods must name at least one method.');
        }
        if (\count($methods) > DeliveryMethod::MAX_ACTIVE) {
            throw new \InvalidArgumentException(\sprintf('The demo delivery methods are all active, so there may be at most %d of them.', DeliveryMethod::MAX_ACTIVE));
        }

        $seen = [];
        foreach ($methods as $method) {
            try {
                $terms = DeliveryMethodTerms::of($method->name, $method->description, $method->priceGross, $method->freeFromGross, $method->position, $method->trackingUrlTemplate);
            } catch (InvalidDeliveryMethod $e) {
                throw new \InvalidArgumentException(\sprintf('The demo delivery method "%s" is invalid at %s: %s', $method->name, $e->field, $e->getMessage()), 0, $e);
            }
            $key = mb_strtolower($terms->name->toString());
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(\sprintf('The demo delivery method "%s" is listed twice.', $terms->name->toString()));
            }
            $seen[$key] = true;
        }
        $this->methods = $methods;
    }

    /**
     * @return list<DemoDeliveryMethod>
     */
    public function methods(): array
    {
        return $this->methods;
    }
}
