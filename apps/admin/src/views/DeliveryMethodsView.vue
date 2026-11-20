<script setup lang="ts">
import { Pencil, Plus } from "lucide-vue-next";
import { RouterLink } from "vue-router";
import DataTable, { type ListColumn } from "@/components/list/DataTable.vue";
import ListEmpty from "@/components/list/ListEmpty.vue";
import RowActions, { type RowAction } from "@/components/list/RowActions.vue";
import PageHeader from "@/components/PageHeader.vue";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { ACTIVE_LIMIT, type DeliveryMethodItem, EMPTY_MESSAGE, useDeliveryMethodList } from "@/delivery/useDeliveryMethodList";
import { formatMoney } from "@/prices/format";
import { CURRENCY } from "@/promotions/format";

const list = useDeliveryMethodList();
const { items, loading, loaded, error, forbidden, activeCount } = list;

const priceOf = (method: DeliveryMethodItem) => formatMoney(method.priceGross, CURRENCY);
const freeFromOf = (method: DeliveryMethodItem) => (method.freeFromGross === null ? "—" : formatMoney(method.freeFromGross, CURRENCY));

const columns: ListColumn<DeliveryMethodItem>[] = [
  { key: "name", header: "Nazwa", main: true },
  { key: "price", header: "Cena", align: "right" },
  { key: "freeFrom", header: "Darmowa od", hideBelow: "lg", align: "right", text: freeFromOf },
  { key: "position", header: "Kolejność", hideBelow: "lg", align: "right", text: (method) => String(method.position) },
  { key: "status", header: "Status" },
  { key: "actions", header: "Akcje", align: "right" },
];

// An inactive method is shown in a muted tone, below the active ones (the order is the API's).
const tone = (method: DeliveryMethodItem) => (method.active ? "" : "text-muted-foreground");

const actionsOf = (method: DeliveryMethodItem): RowAction[] => [
  { key: "edit", label: "Edytuj", icon: Pencil, to: { name: "delivery-method", params: { id: method.id } } },
];
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader
      title="Dostawa"
      :crumbs="[{ label: 'Sklep' }, { label: 'Dostawa' }]"
    />

    <Card class="gap-0 py-0">
      <div
        v-if="!forbidden"
        class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-separator px-4 py-3"
      >
        <p
          v-if="loaded"
          data-testid="active-count"
          class="text-sm font-semibold text-heading"
        >
          Aktywne: {{ activeCount }} z {{ ACTIVE_LIMIT }}
        </p>
        <span v-else />
        <Button as-child>
          <RouterLink
            data-testid="add-delivery-method"
            :to="{ name: 'delivery-method-new' }"
          >
            <Plus aria-hidden="true" />
            Dodaj metodę
          </RouterLink>
        </Button>
      </div>

      <DataTable
        label="Lista metod dostawy"
        :columns="columns"
        :rows="items"
        :row-key="(method: DeliveryMethodItem) => method.id"
        row-testid="delivery-row"
        error-testid="delivery-error"
        :loading="loading"
        :loaded="loaded"
        :error="error"
        :forbidden="forbidden"
        :retry="list.retry"
      >
        <template #cell-name="{ row }">
          <RouterLink
            data-testid="method-link"
            class="rounded-sm font-bold break-words hover:underline"
            :class="row.active ? 'text-heading' : 'text-muted-foreground'"
            :to="{ name: 'delivery-method', params: { id: row.id } }"
          >
            {{ row.name }}
          </RouterLink>
        </template>
        <template #cell-price="{ row }">
          <span
            data-testid="method-price"
            class="tabular-nums"
            :class="tone(row)"
          >{{ priceOf(row) }}</span>
        </template>
        <template #cell-freeFrom="{ row }">
          <span
            data-testid="method-free-from"
            class="tabular-nums"
            :class="tone(row)"
          >{{ freeFromOf(row) }}</span>
        </template>
        <template #cell-position="{ row }">
          <span
            data-testid="method-position"
            class="tabular-nums"
            :class="tone(row)"
          >{{ row.position }}</span>
        </template>
        <template #cell-status="{ row }">
          <Badge
            data-testid="method-status"
            :variant="row.active ? 'success' : 'neutral'"
          >
            {{ row.active ? "Aktywna" : "Nieaktywna" }}
          </Badge>
        </template>
        <template #cell-actions="{ row }">
          <RowActions
            :name="row.name"
            :actions="actionsOf(row)"
          />
        </template>
        <template #empty>
          <ListEmpty
            :filtered="false"
            :empty-text="EMPTY_MESSAGE"
          >
            <template #action>
              <Button as-child>
                <RouterLink
                  data-testid="empty-add-delivery-method"
                  :to="{ name: 'delivery-method-new' }"
                >
                  <Plus aria-hidden="true" />
                  Dodaj metodę
                </RouterLink>
              </Button>
            </template>
          </ListEmpty>
        </template>
      </DataTable>
    </Card>
  </div>
</template>
