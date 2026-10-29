<script setup lang="ts">
import Card from "primevue/card";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Message from "primevue/message";
import Tag from "primevue/tag";
import type { Dashboard } from "./useDashboard";

type System = NonNullable<Dashboard["system"]>;

defineProps<{ system: System }>();
</script>

<template>
  <section aria-labelledby="system-heading">
    <h2 id="system-heading">
      Stan systemu
    </h2>

    <h3>Usługi</h3>
    <div class="tiles">
      <Card
        v-for="service in system.services"
        :key="service.name"
        data-testid="service"
      >
        <template #content>
          <div class="service">
            <strong data-testid="service-name">{{ service.name }}</strong>
            <Tag
              data-testid="service-tag"
              :severity="service.status === 'up' ? 'success' : 'danger'"
              :value="service.status"
            />
            <span
              v-if="service.latencyMs !== null"
              class="latency"
            >{{ service.latencyMs }} ms</span>
          </div>
        </template>
      </Card>
    </div>

    <h3>Outbox</h3>
    <p>
      Zdarzenia czekające w outboxie:
      <strong data-testid="outbox-pending">{{ system.outbox.pending }}</strong>
    </p>

    <h3>Kolejki</h3>
    <DataTable
      v-if="system.queues !== null"
      data-testid="queues"
      :value="system.queues"
    >
      <Column
        field="name"
        header="Nazwa"
      />
      <Column
        field="messages"
        header="Wiadomości"
      />
      <Column
        field="consumers"
        header="Konsumenci"
      />
      <template #empty>
        Brak kolejek.
      </template>
    </DataTable>
    <Message
      v-else
      severity="secondary"
    >
      Brak danych o kolejkach
    </Message>

    <h3>Indeks wyszukiwania</h3>
    <p data-testid="search-index">
      Dokumenty w Elasticsearch: <strong>{{ system.searchIndex.indexedProducts ?? "brak danych" }}</strong>,
      produkty w bazie: <strong>{{ system.searchIndex.catalogProducts }}</strong>
    </p>
    <Message
      v-if="!system.searchIndex.inSync"
      data-testid="out-of-sync"
      severity="warn"
    >
      Rozbieżność: w indeksie {{ system.searchIndex.indexedProducts ?? "nieznana liczba" }} dokumentów,
      a w bazie {{ system.searchIndex.catalogProducts }} produktów.
    </Message>
  </section>
</template>

<style scoped>
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
  gap: 1rem;
}

.service {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.latency {
  margin-left: auto;
  color: var(--p-text-muted-color);
}
</style>
