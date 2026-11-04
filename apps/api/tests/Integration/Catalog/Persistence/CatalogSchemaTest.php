<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The migrated test database and the Doctrine mapping describe the same schema (what `doctrine:schema:validate`
 * and an empty `doctrine:migrations:diff` check), including the variant tables and the column PostgreSQL generates.
 */
final class CatalogSchemaTest extends KernelTestCase
{
    public function testMigratedSchemaMatchesTheMapping(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $tool = new SchemaTool($entityManager);

        $updates = array_values(array_filter(
            $tool->getUpdateSchemaSql($entityManager->getMetadataFactory()->getAllMetadata()),
            static fn (string $sql): bool => str_contains($sql, 'catalog_'),
        ));

        self::assertSame([], $updates);
    }
}
