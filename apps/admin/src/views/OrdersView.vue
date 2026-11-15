<script setup lang="ts">
import { useMediaQuery } from "@vueuse/core";
import { computed, nextTick, ref, useId } from "vue";
import { RouterLink } from "vue-router";
import DataTable, { type ListColumn } from "@/components/list/DataTable.vue";
import ListEmpty from "@/components/list/ListEmpty.vue";
import ListPager from "@/components/list/ListPager.vue";
import ListToolbar from "@/components/list/ListToolbar.vue";
import PageHeader from "@/components/PageHeader.vue";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { type OrderStatus, STATUS_OPTIONS, statusLabel } from "@/orders/format";
import { EMPTY_MESSAGE, MAX_SEARCH_LENGTH, type OrderListItem, useOrderList } from "@/orders/useOrderList";
import { formatDateTime, formatMoney } from "@/prices/format";

const list = useOrderList();
const { search, query, filtered, items, loading, loaded, error, forbidden, hasPrevious, hasNext, pageNumber, range } = list;

// One layout at a time: a table from 640 px, cards below it (the rows and their test ids are never in the DOM twice).
const tableLayout = useMediaQuery("(min-width: 640px)");

const SEARCH_HINT = "Dokładny numer (np. R-100001) albo dokładny e-mail, zatwierdź Enterem.";
const ALL_STATUSES = "all";

const statusFilterId = useId();
const statusFilterLabel = computed(() => STATUS_OPTIONS.find((option) => option.value === query.value.status)?.label ?? "Wszystkie");

function selectStatus(value: unknown): void {
  const option = STATUS_OPTIONS.find((candidate) => candidate.value === value);
  list.selectStatus(option === undefined ? null : (option.value as OrderStatus));
}

const refundPending = computed(() => query.value.refund);
const title = computed(() => (refundPending.value ? "Zwroty do wykonania" : "Zamówienia"));
const crumbs = computed(() => (refundPending.value ? [{ label: "Zamówienia", to: { name: "orders" } }, { label: "Zwroty do wykonania" }] : [{ label: "Zamówienia" }]));

const dateOf = (order: OrderListItem) => formatDateTime(order.placedAt);
const refundAmountOf = (order: OrderListItem) => (order.refund ? formatMoney(order.refund.amount, order.currency) : "—");
const refundDueOf = (order: OrderListItem) => (order.refund ? formatDateTime(order.refund.dueAt) : "—");

const columns = computed<ListColumn<OrderListItem>[]>(() => [
  { key: "number", header: "Numer", main: true },
  { key: "placedAt", header: "Data", hideBelow: "lg", text: dateOf },
  { key: "customer", header: "Klient" },
  { key: "items", header: "Pozycje", hideBelow: "lg", align: "right", text: (order) => String(order.itemCount) },
  { key: "total", header: "Kwota", align: "right" },
  { key: "status", header: "Status" },
  ...(refundPending.value
    ? [
        { key: "refundAmount", header: "Kwota zwrotu", align: "right" as const },
        { key: "refundDue", header: "Termin zwrotu" },
      ]
    : []),
]);

const toolbar = ref<InstanceType<typeof ListToolbar> | null>(null);

// „Wyczyść filtry” goes away with the filters, so the focus goes to the search field.
async function clearFilters() {
  list.clearFilters();
  await nextTick();
  toolbar.value?.focusSearch();
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader
      :title="title"
      :crumbs="crumbs"
    />

    <Card class="gap-0 py-0">
      <ListToolbar
        ref="toolbar"
        v-model:search="search"
        search-label="Szukaj po numerze zamówienia lub e-mailu"
        search-placeholder="Numer zamówienia lub e-mail"
        :search-max="MAX_SEARCH_LENGTH"
        :search-hint="SEARCH_HINT"
        :filtered="filtered"
        :per-page="query.perPage"
        @update:per-page="list.setPerPage"
        @submit="list.submitSearch"
        @clear="clearFilters"
      >
        <template #filters>
          <div class="flex flex-col gap-1.5">
            <Label :for="statusFilterId">
              Status
            </Label>
            <Select
              :model-value="query.status ?? ALL_STATUSES"
              @update:model-value="selectStatus"
            >
              <SelectTrigger
                :id="statusFilterId"
                data-testid="status-filter"
                class="w-52"
              >
                <SelectValue>{{ statusFilterLabel }}</SelectValue>
              </SelectTrigger>
              <SelectContent>
                <SelectItem :value="ALL_STATUSES">
                  Wszystkie
                </SelectItem>
                <SelectItem
                  v-for="option in STATUS_OPTIONS"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
          </div>
          <div class="flex items-center gap-2 self-end pb-2">
            <Switch
              id="refund-pending"
              data-testid="refund-pending"
              :model-value="refundPending"
              @update:model-value="list.setRefundPending($event === true)"
            />
            <Label for="refund-pending">
              Zwroty do wykonania
            </Label>
          </div>
        </template>
      </ListToolbar>

      <DataTable
        label="Zamówienia"
        :cards="!tableLayout"
        :columns="columns"
        :rows="items"
        :row-key="(order: OrderListItem) => order.orderId"
        row-testid="order-row"
        error-testid="orders-error"
        :loading="loading"
        :loaded="loaded"
        :error="error"
        :forbidden="forbidden"
        :retry="list.retry"
      >
        <template #cell-number="{ row }">
          <RouterLink
            data-testid="number"
            class="rounded-sm font-bold whitespace-nowrap text-heading hover:underline"
            :to="{ name: 'order-detail', params: { orderId: row.orderId } }"
          >
            {{ row.number }}
          </RouterLink>
        </template>
        <template #cell-placedAt="{ row }">
          <span data-testid="placed-at">{{ dateOf(row) }}</span>
        </template>
        <template #cell-customer="{ row }">
          <span
            data-testid="full-name"
            class="block"
          >{{ row.fullName }}</span>
          <span
            data-testid="email"
            :title="row.email"
            class="block max-w-64 truncate text-xs text-muted-foreground"
          >{{ row.email }}</span>
        </template>
        <template #cell-items="{ row }">
          <span
            data-testid="items"
            class="tabular-nums"
          >{{ row.itemCount }}</span>
        </template>
        <template #cell-total="{ row }">
          <span
            data-testid="total"
            class="whitespace-nowrap tabular-nums"
          >{{ formatMoney(row.totalGross, row.currency) }}</span>
        </template>
        <template #cell-status="{ row }">
          <Badge
            data-testid="status"
            :variant="statusLabel(row.status).variant"
          >
            {{ statusLabel(row.status).label }}
          </Badge>
        </template>
        <template #cell-refundAmount="{ row }">
          <span
            data-testid="refund-amount"
            class="whitespace-nowrap tabular-nums"
          >{{ refundAmountOf(row) }}</span>
        </template>
        <template #cell-refundDue="{ row }">
          <div class="flex flex-wrap items-center gap-2">
            <span data-testid="refund-due">{{ refundDueOf(row) }}</span>
            <Badge
              v-if="row.refund?.overdue"
              data-testid="refund-overdue"
              variant="danger"
            >
              Po terminie
            </Badge>
          </div>
        </template>

        <template #card="{ row }">
          <div class="flex flex-col gap-2">
            <div class="flex items-start justify-between gap-3">
              <RouterLink
                data-testid="number"
                class="rounded-sm font-bold whitespace-nowrap text-heading hover:underline"
                :to="{ name: 'order-detail', params: { orderId: row.orderId } }"
              >
                {{ row.number }}
              </RouterLink>
              <Badge
                data-testid="status"
                :variant="statusLabel(row.status).variant"
              >
                {{ statusLabel(row.status).label }}
              </Badge>
            </div>
            <div class="min-w-0 text-sm">
              <span
                data-testid="full-name"
                class="block"
              >{{ row.fullName }}</span>
              <span
                data-testid="email"
                class="block text-xs break-all text-muted-foreground"
              >{{ row.email }}</span>
            </div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
              <span
                data-testid="total"
                class="font-bold whitespace-nowrap tabular-nums"
              >{{ formatMoney(row.totalGross, row.currency) }}</span>
              <span class="text-xs text-muted-foreground">Pozycje: <span
                data-testid="items"
                class="tabular-nums"
              >{{ row.itemCount }}</span></span>
              <span
                data-testid="placed-at"
                class="text-xs text-muted-foreground"
              >{{ dateOf(row) }}</span>
            </div>
            <div
              v-if="refundPending && row.refund"
              class="flex flex-col gap-1 border-t border-separator pt-2 text-sm"
            >
              <span>Kwota zwrotu: <span
                data-testid="refund-amount"
                class="font-bold whitespace-nowrap tabular-nums"
              >{{ refundAmountOf(row) }}</span></span>
              <span class="flex flex-wrap items-center gap-2">Termin zwrotu: <span data-testid="refund-due">{{ refundDueOf(row) }}</span>
                <Badge
                  v-if="row.refund.overdue"
                  data-testid="refund-overdue"
                  variant="danger"
                >
                  Po terminie
                </Badge></span>
            </div>
          </div>
        </template>

        <template #empty>
          <ListEmpty
            :filtered="filtered"
            :later-page="hasPrevious"
            :empty-text="EMPTY_MESSAGE"
            @clear="clearFilters"
          />
        </template>
      </DataTable>

      <div
        v-if="loaded && (items.length > 0 || hasPrevious)"
        class="border-t border-separator px-4 py-3"
      >
        <ListPager
          :has-previous="hasPrevious"
          :has-next="hasNext"
          :page-number="pageNumber"
          :range="range"
          @previous="list.goPrevious"
          @next="list.goNext"
        />
      </div>
    </Card>
  </div>
</template>
