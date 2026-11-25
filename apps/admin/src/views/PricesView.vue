<script setup lang="ts">
import { History, Pencil, TriangleAlert } from "lucide-vue-next";
import { computed, nextTick, onMounted, ref } from "vue";
import CategoryCombobox from "@/components/CategoryCombobox.vue";
import DataTable, { type ListColumn } from "@/components/list/DataTable.vue";
import ListEmpty from "@/components/list/ListEmpty.vue";
import ListPager from "@/components/list/ListPager.vue";
import ListToolbar from "@/components/list/ListToolbar.vue";
import RowActions, { type RowAction } from "@/components/list/RowActions.vue";
import PageHeader from "@/components/PageHeader.vue";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { useCategoryTree } from "@/composables/useCategoryTree";
import { variantName } from "@/lib/variantName";
import { formatMoney, formatRate } from "@/prices/format";
import PriceDialogs from "@/prices/PriceDialogs.vue";
import { EMPTY_MESSAGE, MAX_SEARCH_LENGTH, type PriceRow, usePriceList } from "@/prices/usePriceList";
import { fetchTaxClasses, type TaxClass, taxClassName } from "@/products/taxClasses";
import { useAuthStore } from "@/stores/auth";

const categories = useCategoryTree();
const list = usePriceList({ nodes: categories.nodes, loaded: categories.loaded });
const { search, searchHint, query, filtered, items, rows, loading, loaded, error, forbidden, hasPrevious, hasNext, pageNumber, range, total, changePrice } = list;

// The Polish names of the tax classes for the row of a product; without them (a failed request) the row shows the code.
const taxClasses = ref<TaxClass[]>([]);
onMounted(async () => {
  const result = await fetchTaxClasses();
  if ("taxClasses" in result) {
    taxClasses.value = result.taxClasses;
  }
});

// Only hides the action: the API checks the permission on every change. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canChangePrices = computed(() => auth.staff?.roles.includes("admin") ?? false);

const header = ref<InstanceType<typeof PageHeader> | null>(null);
const toolbar = ref<InstanceType<typeof ListToolbar> | null>(null);
const dialogs = ref<InstanceType<typeof PriceDialogs> | null>(null);

// „Wyczyść filtry” goes away with the filters, so the focus goes to the search field.
async function clearFilters() {
  list.clearFilters();
  await nextTick();
  toolbar.value?.focusSearch();
}

const money = (amount: number, currency: string) => formatMoney(amount, currency);

const columns: ListColumn<PriceRow>[] = [
  { key: "variant", header: "Wariant", main: true },
  { key: "gross", header: "Brutto", align: "right" },
  { key: "net", header: "Netto", align: "right", hideBelow: "lg", text: (row) => (row.price ? money(row.price.net, row.price.currency) : "—") },
  { key: "vat", header: "VAT", align: "right", hideBelow: "lg", text: (row) => (row.price ? money(row.price.vat, row.price.currency) : "—") },
  { key: "rate", header: "Stawka" },
  { key: "actions", header: "Akcje", align: "right" },
];


const rowActions = computed<RowAction[]>(() => [
  ...(canChangePrices.value ? [{ key: "change", label: "Zmień cenę", icon: Pencil, testid: "change-price" }] : []),
  { key: "history", label: "Historia", icon: History, testid: "show-history" },
]);

function onAction(key: string, row: PriceRow, trigger: HTMLElement): void {
  if (key === "change") {
    dialogs.value?.openChange(row, trigger);
  } else {
    dialogs.value?.openHistory(row, trigger);
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader
      ref="header"
      title="Ceny"
      :crumbs="[{ label: 'Katalog' }, { label: 'Ceny' }]"
    />

    <Card class="gap-0 py-0">
      <ListToolbar
        ref="toolbar"
        v-model:search="search"
        search-label="Szukaj po nazwie produktu"
        search-placeholder="Szukaj po nazwie"
        :search-max="MAX_SEARCH_LENGTH"
        :search-hint="searchHint"
        :filtered="filtered"
        :per-page="query.perPage"
        :sort-options="list.sortOptions"
        :sort="query.sort"
        @update:per-page="list.setPerPage"
        @update:sort="list.setSort"
        @clear="clearFilters"
      >
        <template #filters>
          <CategoryCombobox
            data-testid="category-filter"
            class="w-full sm:w-64"
            label="Kategoria"
            placeholder="Wszystkie kategorie"
            :nodes="categories.nodes.value"
            :model-value="query.category"
            deselectable
            :disabled="categories.nodes.value.length === 0"
            @update:model-value="list.selectCategory(($event as string | null) ?? null)"
          />
        </template>
      </ListToolbar>

      <Alert
        v-if="categories.error.value"
        data-testid="categories-error"
        class="m-4 w-auto"
      >
        {{ categories.error.value }}
      </Alert>

      <DataTable
        label="Lista cen"
        :columns="columns"
        :rows="rows"
        :row-key="(row: PriceRow) => row.variantId"
        row-testid="price-row"
        :group-key="(row: PriceRow) => row.productId"
        group-testid="product-row"
        error-testid="prices-error"
        :loading="loading"
        :loaded="loaded"
        :error="error"
        :forbidden="forbidden"
        :retry="list.retry"
      >
        <template #group="{ row }">
          <span
            data-testid="name"
            class="font-bold text-heading"
          >{{ row.name }}</span>
          <span class="sr-only">, klasa podatkowa: </span>
          <span
            data-testid="tax-class"
            class="ml-2 text-xs text-muted-foreground"
          >{{ taxClassName(taxClasses, row.taxClass) }}</span>
        </template>
        <template #cell-variant="{ row }">
          <span
            data-testid="variant-label"
            class="break-words"
          >{{ row.label === "" ? "Wariant domyślny" : row.label }}</span>
        </template>
        <template #cell-gross="{ row }">
          <span
            data-testid="gross"
            class="font-bold whitespace-nowrap text-heading"
          >{{ row.price ? money(row.price.gross, row.price.currency) : "Brak ceny" }}</span>
        </template>
        <template #cell-net="{ row }">
          <span
            data-testid="net"
            class="whitespace-nowrap"
          >{{ row.price ? money(row.price.net, row.price.currency) : "—" }}</span>
        </template>
        <template #cell-vat="{ row }">
          <span
            data-testid="vat"
            class="whitespace-nowrap"
          >{{ row.price ? money(row.price.vat, row.price.currency) : "—" }}</span>
        </template>
        <template #cell-rate="{ row }">
          <div class="flex flex-col items-start gap-1">
            <span data-testid="vat-rate">{{ row.price ? formatRate(row.price.vatRate) : "—" }}</span>
            <Badge
              v-if="row.price?.vatRateOutdated"
              data-testid="outdated"
              variant="warning"
            >
              <TriangleAlert aria-hidden="true" />
              Nieaktualna, obecnie {{ formatRate(row.applicableVatRate) }}
            </Badge>
          </div>
        </template>
        <template #cell-actions="{ row }">
          <RowActions
            :name="variantName(row)"
            :actions="rowActions"
            @select="(key, trigger) => onAction(key, row, trigger)"
          />
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
          noun="produkty"
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

    <PriceDialogs
      ref="dialogs"
      :rows="rows"
      :tax-classes="taxClasses"
      :change-price="changePrice"
      :fallback-focus="() => header?.heading ?? null"
    />
  </div>
</template>
