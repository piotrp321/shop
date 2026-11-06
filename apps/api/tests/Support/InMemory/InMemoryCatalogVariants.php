<?php

declare(strict_types=1);

namespace App\Tests\Support\InMemory;

use App\Catalog\Application\Query\GetVariant;
use App\Catalog\Application\Query\VariantInfo;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * A query bus that answers Catalog's GetVariant from a map of variant id => product id, and records the queries.
 */
final class InMemoryCatalogVariants
{
    /** @var list<string> the variant ids asked for */
    public array $asked = [];

    /**
     * @param array<string, string> $productOfVariant
     */
    public function __construct(private readonly array $productOfVariant = [])
    {
    }

    public function queryBus(): MessageBus
    {
        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            GetVariant::class => [function (GetVariant $query): ?VariantInfo {
                $this->asked[] = $query->variantId;
                $productId = $this->productOfVariant[$query->variantId] ?? null;

                return null === $productId ? null : new VariantInfo($query->variantId, $productId, 'standard');
            }],
        ]))]);
    }
}
