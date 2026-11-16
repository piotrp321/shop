<script setup lang="ts">
import { useMediaQuery } from "@vueuse/core";
import { computed, nextTick, ref } from "vue";
import { RouterLink } from "vue-router";
import DataTable, { type ListColumn } from "@/components/list/DataTable.vue";
import ListEmpty from "@/components/list/ListEmpty.vue";
import ListPager from "@/components/list/ListPager.vue";
import ListToolbar from "@/components/list/ListToolbar.vue";
import PageHeader from "@/components/PageHeader.vue";
import { Card } from "@/components/ui/card";
import { ariaSortOf } from "@/composables/listSort";
import { type CustomerListItem, EMPTY_MESSAGE, MAX_SEARCH_LENGTH, useCustomerList } from "@/customers/useCustomerList";
import { formatDateTime, formatMoney } from "@/prices/format";

const list = useCustomerList();
const { search, query, sort, filtered, items, loading, loaded, error, forbidden, hasPrevious, hasNext, pageNumber, range, total } = list;

// One layout at a time: a table from 640 px, cards below it (the rows and their test ids are never in the DOM twice).
const tableLayout = useMediaQuery("(min-width: 640px)");

const lastOrderOf = (customer: CustomerListItem) => formatDateTime(customer.lastOrderAt);

const columns = computed<ListColumn<CustomerListItem>[]>(() => [
  { key: "customer", header: "Klient", main: true, sort: ariaSortOf(sort.value, "name") },
  { key: "orders", header: "Zamówienia", align: "right", sort: ariaSortOf(sort.value, "orderCount") },
  { key: "paidGross", header: "Wartość", align: "right", sort: ariaSortOf(sort.value, "paidGross") },
  { key: "lastOrderAt", header: "Ostatnie zamówienie", hideBelow: "lg", text: lastOrderOf, sort: ariaSortOf(sort.value, "lastOrderAt") },
]);

const toolbar = ref<InstanceType<typeof ListToolbar> | null>(null);

// „Wyczyść filtry” goes away with the search, so the focus goes to the search field.
async function clearFilters() {
  list.clearFilters();
  await nextTick();
  toolbar.value?.focusSearch();
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader
      title="Klienci"
      :crumbs="[{ label: 'Klienci' }]"
    />

    <Card class="gap-0 py-0">
      <ListToolbar
        ref="toolbar"
        v-model:search="search"
        search-label="Szukaj klienta po e-mailu lub nazwisku"
        search-placeholder="E-mail lub nazwisko"
        :search-max="MAX_SEARCH_LENGTH"
        :search-hint="list.searchHint.value"
        :filtered="filtered"
        :per-page="query.perPage"
        :sort-options="list.sortOptions.value"
        :sort="sort"
        @update:per-page="list.setPerPage"
        @update:sort="list.setSort"
        @submit="list.submitSearch"
        @clear="clearFilters"
      />

      <DataTable
        label="Klienci"
        :cards="!tableLayout"
        :columns="columns"
        :rows="items"
        :row-key="(customer: CustomerListItem) => customer.customerId"
        row-testid="customer-row"
        error-testid="customers-error"
        :loading="loading"
        :loaded="loaded"
        :error="error"
        :forbidden="forbidden"
        :retry="list.retry"
      >
        <template #cell-customer="{ row }">
          <RouterLink
            data-testid="full-name"
            class="block rounded-sm font-bold text-heading hover:underline"
            :to="{ name: 'customer', params: { customerId: row.customerId } }"
          >
            {{ row.fullName }}
          </RouterLink>
          <span
            data-testid="email"
            :title="row.email"
            class="block max-w-64 truncate text-xs text-muted-foreground"
          >{{ row.email }}</span>
        </template>
        <template #cell-orders="{ row }">
          <span
            data-testid="order-count"
            class="tabular-nums"
          >{{ row.orderCount }}</span>
        </template>
        <template #cell-paidGross="{ row }">
          <span
            data-testid="paid-gross"
            class="whitespace-nowrap tabular-nums"
          >{{ formatMoney(row.paidGross, row.currency) }}</span>
        </template>
        <template #cell-lastOrderAt="{ row }">
          <span data-testid="last-order-at">{{ lastOrderOf(row) }}</span>
        </template>

        <template #card="{ row }">
          <div class="flex flex-col gap-2">
            <div class="min-w-0">
              <RouterLink
                data-testid="full-name"
                class="block rounded-sm font-bold text-heading hover:underline"
                :to="{ name: 'customer', params: { customerId: row.customerId } }"
              >
                {{ row.fullName }}
              </RouterLink>
              <span
                data-testid="email"
                class="block text-xs break-all text-muted-foreground"
              >{{ row.email }}</span>
            </div>
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
              <span
                data-testid="paid-gross"
                class="font-bold whitespace-nowrap tabular-nums"
              >{{ formatMoney(row.paidGross, row.currency) }}</span>
              <span class="text-xs text-muted-foreground">Zamówienia: <span
                data-testid="order-count"
                class="tabular-nums"
              >{{ row.orderCount }}</span></span>
              <span
                data-testid="last-order-at"
                class="text-xs text-muted-foreground"
              >{{ lastOrderOf(row) }}</span>
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
          :total="total"
          @previous="list.goPrevious"
          @next="list.goNext"
        />
      </div>
    </Card>
  </div>
</template>
