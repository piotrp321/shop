<?php

declare(strict_types=1);

namespace App\Pricing\Infrastructure\Persistence\Doctrine;

use App\Pricing\Application\Port\PersistenceSession;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Clears the entity manager, or replaces it when a failed write closed it. The registry resets the manager service
 * in place, so the repositories holding it get the new one, as the services resetter does between messages.
 */
final readonly class DoctrinePersistenceSession implements PersistenceSession
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    public function reset(): void
    {
        $manager = $this->registry->getManager();
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $this->registry->resetManager();

            return;
        }

        $manager->clear();
    }
}
