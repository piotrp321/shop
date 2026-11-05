<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence\Doctrine;

use App\Ordering\Domain\OrderNumber;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Adds the sequence of the order numbers to the schema Doctrine generates from the mapping (a mapping can name a
 * sequence only for an id), so doctrine:schema:validate and the migration diff know it instead of dropping it.
 * The migration creates it.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final readonly class OrderNumberSequenceSchema
{
    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $schema = $event->getSchema();
        if (!$schema->hasSequence(DbalOrderNumbers::SEQUENCE)) {
            $schema->createSequence(DbalOrderNumbers::SEQUENCE, 1, OrderNumber::FIRST);
        }
    }
}
