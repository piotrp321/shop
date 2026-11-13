<script setup lang="ts">
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { serviceLabel, serviceStatusLabel } from "./labels";
import type { Dashboard } from "./useDashboard";

type System = NonNullable<Dashboard["system"]>;

defineProps<{ system: System }>();
</script>

<template>
  <div class="flex flex-col gap-6">
    <section
      aria-labelledby="services-heading"
      class="flex flex-col gap-4"
    >
      <h2
        id="services-heading"
        class="text-base font-bold text-heading"
      >
        Usługi
      </h2>
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <Card
          v-for="service in system.services"
          :key="service.name"
          data-testid="service"
          class="gap-3 p-4"
        >
          <strong
            data-testid="service-name"
            class="font-bold text-heading"
          >{{ serviceLabel(service.name) }}</strong>
          <div class="flex items-center gap-3">
            <Badge
              data-testid="service-tag"
              :variant="service.status === 'up' ? 'success' : 'danger'"
            >
              {{ serviceStatusLabel(service.status) }}
            </Badge>
            <span
              v-if="service.latencyMs !== null"
              class="ml-auto text-sm text-muted-foreground tabular-nums"
            >{{ service.latencyMs }} ms</span>
          </div>
        </Card>
      </div>
    </section>

    <Card class="gap-3 p-5">
      <h2 class="text-base font-bold text-heading">
        Zdarzenia czekające na wysłanie
      </h2>
      <p class="text-2xl font-bold text-heading tabular-nums">
        <span data-testid="outbox-pending">{{ system.outbox.pending }}</span>
      </p>
    </Card>

    <Card class="gap-4 p-5">
      <h2 class="text-base font-bold text-heading">
        Kolejki
      </h2>
      <div
        v-if="system.queues !== null"
        role="region"
        aria-label="Kolejki"
        tabindex="0"
        class="overflow-x-auto"
      >
        <table
          data-testid="queues"
          class="w-full caption-bottom text-sm"
        >
          <TableHeader>
            <TableRow class="hover:bg-transparent">
              <TableHead class="px-3 text-xs font-bold text-muted-foreground uppercase">
                Nazwa
              </TableHead>
              <TableHead class="px-3 text-right text-xs font-bold text-muted-foreground uppercase">
                Wiadomości
              </TableHead>
              <TableHead class="px-3 text-right text-xs font-bold text-muted-foreground uppercase">
                Konsumenci
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow
              v-for="queue in system.queues"
              :key="queue.name"
            >
              <TableCell class="px-3 font-bold">
                {{ queue.name }}
              </TableCell>
              <TableCell class="px-3 text-right tabular-nums">
                {{ queue.messages }}
              </TableCell>
              <TableCell class="px-3 text-right tabular-nums">
                {{ queue.consumers }}
              </TableCell>
            </TableRow>
            <TableRow
              v-if="system.queues.length === 0"
              class="hover:bg-transparent"
            >
              <TableCell
                colspan="3"
                class="px-3 text-muted-foreground"
              >
                Brak kolejek.
              </TableCell>
            </TableRow>
          </TableBody>
        </table>
      </div>
      <p
        v-else
        class="text-sm text-muted-foreground"
      >
        Brak danych o kolejkach
      </p>
    </Card>

    <Card class="gap-3 p-5">
      <h2 class="text-base font-bold text-heading">
        Indeks wyszukiwania
      </h2>
      <p
        data-testid="search-index"
        class="text-sm"
      >
        Dokumenty w Elasticsearch: <strong>{{ system.searchIndex.indexedProducts ?? "brak danych" }}</strong>,
        produkty w bazie: <strong>{{ system.searchIndex.catalogProducts }}</strong>
      </p>
      <p
        v-if="!system.searchIndex.inSync"
        data-testid="out-of-sync"
        role="status"
        class="rounded-lg border border-status-warning bg-status-warning-soft px-4 py-3 text-sm text-status-warning"
      >
        Rozbieżność: w indeksie {{ system.searchIndex.indexedProducts ?? "nieznana liczba" }} dokumentów,
        a w bazie {{ system.searchIndex.catalogProducts }} produktów.
      </p>
    </Card>
  </div>
</template>
