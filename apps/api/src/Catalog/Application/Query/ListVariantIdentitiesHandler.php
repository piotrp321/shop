<?php

declare(strict_types=1);

namespace App\Catalog\Application\Query;

use App\Catalog\Application\Port\VariantSource;
use App\Catalog\Domain\Product\VariantId;
use App\Catalog\Domain\Product\VariantKey;
use App\Catalog\Domain\Product\VariantValues;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class ListVariantIdentitiesHandler
{
    public function __construct(private VariantSource $source)
    {
    }

    /**
     * @return list<VariantIdentity>
     *
     * @throws \InvalidArgumentException when the limit is out of range or the cursor is not a UUID
     */
    public function __invoke(ListVariantIdentities $query): array
    {
        if ($query->limit < 1 || $query->limit > ListVariantIdentities::MAX_LIMIT) {
            throw new \InvalidArgumentException('The batch limit must be between 1 and 1000.');
        }

        return array_map(
            static fn (array $row): VariantIdentity => new VariantIdentity(
                $row['id'],
                VariantKey::of($row['productSlug'], VariantValues::fromList($row['values'])),
                $row['taxClass'],
            ),
            $this->source->page(null === $query->after ? null : VariantId::fromString($query->after), $query->limit),
        );
    }
}
