<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

use App\Backoffice\Application\Port\CatalogCounts;
use App\Backoffice\Application\Port\CatalogStatistics;
use App\Backoffice\Application\Port\MessagingStatistics;
use App\Backoffice\Application\Port\SystemHealth;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetDashboardHandler
{
    /** Services in the order they are reported, whatever the order of the probes. */
    private const array SERVICE_ORDER = ['postgres', 'redis', 'elasticsearch', 'rabbitmq', 'keycloak', 'garage', 'imgproxy', 'mailpit'];

    public function __construct(
        private CatalogStatistics $catalog,
        private SystemHealth $health,
        private MessagingStatistics $messaging,
    ) {
    }

    public function __invoke(GetDashboard $query): Dashboard
    {
        if (!$query->includeSystem) {
            return new Dashboard($this->catalogSection($this->catalog->counts()), null);
        }

        // The slow requests of the system section are sent first and collected together, before the catalogue
        // is read: the catalogue may take its own time (search index) and the answers must not wait for it.
        $collectServices = $this->health->start();
        $collectQueues = $this->messaging->startQueues();
        $services = $collectServices();
        $queues = $collectQueues();
        $outboxPending = $this->messaging->outboxPending();

        $counts = $this->catalog->counts();

        return new Dashboard($this->catalogSection($counts), $this->system($counts, $services, $queues, $outboxPending));
    }

    private function catalogSection(CatalogCounts $counts): CatalogSection
    {
        return new CatalogSection($counts->productCount, $counts->categoryCount, $counts->productsWithoutImages);
    }

    /**
     * @param list<ServiceStatus>    $services
     * @param list<QueueStatus>|null $queues
     */
    private function system(CatalogCounts $counts, array $services, ?array $queues, int $outboxPending): SystemSection
    {
        return new SystemSection(
            $this->orderedServices($services),
            $outboxPending,
            $queues,
            new SearchIndexStatus(
                $counts->indexedProducts,
                $counts->productCount,
                null !== $counts->indexedProducts && $counts->indexedProducts === $counts->productCount,
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
