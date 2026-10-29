<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

use App\Backoffice\Application\Port\CatalogStatistics;
use App\Backoffice\Application\Port\CatalogStatisticsProvider;
use App\Backoffice\Application\Port\MessagingStatistics;
use App\Backoffice\Application\Port\SystemHealth;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetDashboardHandler
{
    /** Services in the order they are reported, whatever the order of the probes. */
    private const array SERVICE_ORDER = ['postgres', 'redis', 'elasticsearch', 'rabbitmq', 'keycloak', 'garage', 'imgproxy', 'mailpit'];

    public function __construct(
        private CatalogStatisticsProvider $catalog,
        private SystemHealth $health,
        private MessagingStatistics $messaging,
    ) {
    }

    public function __invoke(GetDashboard $query): Dashboard
    {
        $statistics = $this->catalog->statistics();
        $catalog = new CatalogSection($statistics->productCount, $statistics->categoryCount, $statistics->productsWithoutImages);

        return new Dashboard($catalog, $query->includeSystem ? $this->system($statistics) : null);
    }

    private function system(CatalogStatistics $statistics): SystemSection
    {
        return new SystemSection(
            $this->orderedServices($this->health->services()),
            $this->messaging->outboxPending(),
            $this->messaging->queues(),
            new SearchIndexStatus(
                $statistics->indexedProducts,
                $statistics->productCount,
                null !== $statistics->indexedProducts && $statistics->indexedProducts === $statistics->productCount,
            ),
        );
    }

    /**
     * @param list<ServiceStatus> $services
     *
     * @return list<ServiceStatus>
     */
    private function orderedServices(array $services): array
    {
        $position = static function (ServiceStatus $service): int {
            $index = array_search($service->name, self::SERVICE_ORDER, true);

            return false === $index ? \count(self::SERVICE_ORDER) : $index;
        };
        usort($services, static fn (ServiceStatus $a, ServiceStatus $b): int => $position($a) <=> $position($b));

        return $services;
    }
}
