<?php

declare(strict_types=1);

namespace App\Backoffice\Application\Query;

use App\Backoffice\Application\Port\CatalogCounts;
use App\Backoffice\Application\Port\CatalogStatistics;
use App\Backoffice\Application\Port\MessagingStatistics;
use App\Backoffice\Application\Port\OrderQueueCounts;
use App\Backoffice\Application\Port\OrderQueues;
use App\Backoffice\Application\Port\StockQueues;
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
        private OrderQueues $orderQueues,
        private StockQueues $stockQueues,
    ) {
    }

    public function __invoke(GetDashboard $query): Dashboard
    {
        $access = $query->queues;
        if (!$query->includeSystem) {
            $counts = $this->catalog->counts($access->manageProducts);

            return new Dashboard($this->catalogSection($counts), $this->queues($access, $counts), null);
        }

        // The slow requests of the system section are sent first and collected together, before the catalogue
        // is read: the catalogue may take its own time (search index) and the answers must not wait for it.
        $collectServices = $this->health->start();
        $collectQueues = $this->messaging->startQueues();
        $services = $collectServices();
        $queues = $collectQueues();
        $outboxPending = $this->messaging->outboxPending();

        $counts = $this->catalog->counts($access->manageProducts);

        return new Dashboard($this->catalogSection($counts), $this->queues($access, $counts), $this->system($counts, $services, $queues, $outboxPending));
    }

    private function catalogSection(CatalogCounts $counts): CatalogSection
    {
        return new CatalogSection($counts->productCount, $counts->categoryCount);
    }

    /**
     * Each owner is asked only for the queues the staff member may see (the voters of the owners decided that), and
     * not at all when there are none.
     */
    private function queues(QueueAccess $access, CatalogCounts $catalog): QueuesSection
    {
        $refunds = $access->completeRefunds;
        $orders = $access->viewOrders || $access->viewPendingPayments || $refunds
            ? $this->orderQueues->counts($access->viewOrders, $access->viewPendingPayments, $refunds)
            : new OrderQueueCounts(null, null, null, null);
        $stock = $access->manageStock ? $this->stockQueues->counts() : null;

        return new QueuesSection(
            $orders->paidToShip,
            $orders->awaitingPayment,
            $orders->pendingRefunds,
            $orders->overdueRefunds,
            $stock?->outOfStockVariants,
            $stock?->lowStockVariants,
            $access->manageProducts ? $catalog->productsWithoutImages : null,
        );
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
            new SearchIndexStatus($counts->indexedProducts, $counts->productCount, self::inSync($counts->indexedProducts, $counts->productCount)),
        );
    }

    /**
     * The index mirrors the catalogue within 1% of the estimate: the number of products is only an estimate, and a
     * product indexed a moment ago or not yet is no fault. Without an estimate there is nothing to compare with (null);
     * an index that cannot be counted is not in sync.
     */
    private static function inSync(?int $indexed, ?int $estimate): ?bool
    {
        if (null === $estimate) {
            return null;
        }

        return null !== $indexed && abs($indexed - $estimate) * 100 <= $estimate;
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
