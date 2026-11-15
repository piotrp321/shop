<script setup lang="ts">
import { ClipboardPen, History, PackagePlus } from "lucide-vue-next";
import { computed, nextTick, ref, useId } from "vue";
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
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { useCategoryTree } from "@/composables/useCategoryTree";
import { variantName } from "@/lib/variantName";
import { levelLabel } from "@/inventory/format";
import StockDialogs from "@/inventory/StockDialogs.vue";
import { EMPTY_MESSAGE, LEVEL_EXCLUDES_MESSAGE, LEVEL_FILTERS, MAX_SEARCH_LENGTH, type StockRow, useStockList } from "@/inventory/useStockList";
import { useAuthStore } from "@/stores/auth";

const categories = useCategoryTree();
const list = useStockList({ nodes: categories.nodes, loaded: categories.loaded });
const { search, searchHint, query, filtered, items, rows, loading, loaded, error, forbidden, hasPrevious, hasNext, pageNumber, range, total, receiveStock, correctStock } = list;

// The filter „Poziom”: while a level is on, the search and the category are off and the description says why.
const levelFilterId = useId();
const levelHintId = useId();
const ALL_LEVELS = "all";
const levelFilterLabel = computed(() => (list.level.value === null ? "Wszystkie" : LEVEL_FILTERS.find((filter) => filter.value === list.level.value)?.label));
const levelHint = computed(() => (list.level.value === null ? null : LEVEL_EXCLUDES_MESSAGE));

// Only hides the actions: the API checks the permission on every change. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canChangeStock = computed(() => auth.staff?.roles.some((role) => role === "admin" || role === "warehouse") ?? false);

const header = ref<InstanceType<typeof PageHeader> | null>(null);
const toolbar = ref<InstanceType<typeof ListToolbar> | null>(null);
const dialogs = ref<InstanceType<typeof StockDialogs> | null>(null);

// „Wyczyść filtry” goes away with the filters, so the focus goes to the search field.
async function clearFilters() {
  list.clearFilters();
  await nextTick();
  toolbar.value?.focusSearch();
}

const columns: ListColumn<StockRow>[] = [
  { key: "variant", header: "Wariant", main: true },
  { key: "physical", header: "Fizycznie", align: "right" },
  { key: "reserved", header: "Zarezerwowane", align: "right", hideBelow: "lg", text: (row) => number(row.stock?.reserved) },
  { key: "available", header: "Dostępne", align: "right" },
  { key: "level", header: "Poziom" },
  { key: "actions", header: "Akcje", align: "right" },
];

// A variant without a stock record has no numbers: the panel shows a dash and does not make up a level for it.
const number = (value: number | undefined) => (value === undefined ? "—" : String(value));


const rowActions = computed<RowAction[]>(() => [
  ...(canChangeStock.value
    ? [
        { key: "receive", label: "Przyjęcie", icon: PackagePlus, testid: "receive-stock" },
        { key: "correct", label: "Korekta", icon: ClipboardPen, testid: "correct-stock" },
      ]
    : []),
  { key: "history", label: "Historia", icon: History, testid: "show-history" },
]);

function onAction(key: string, row: StockRow, trigger: HTMLElement): void {
  if (key === "receive") {
    dialogs.value?.openReceipt(row, trigger);
  } else if (key === "correct") {
    dialogs.value?.openCorrection(row, trigger);
  } else {
    dialogs.value?.openHistory(row, trigger);
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader
      ref="header"
      title="Stany magazynowe"
      :crumbs="[{ label: 'Katalog' }, { label: 'Stany magazynowe' }]"
    />

    <Card class="gap-0 py-0">
      <ListToolbar
        ref="toolbar"
        v-model:search="search"
        search-label="Szukaj po nazwie produktu"
        search-placeholder="Szukaj po nazwie"
        :search-max="MAX_SEARCH_LENGTH"
        :search-hint="searchHint"
        :search-disabled="list.level.value !== null"
        :search-described-by="levelHint === null ? undefined : levelHintId"
        :filtered="filtered"
        :per-page="query.perPage"
        :sort-options="list.sortOptions.value"
        :sort="list.sort.value"
        sort-placeholder="Wg produktu"
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
            :disabled="categories.nodes.value.length === 0 || list.level.value !== null"
            :described-by="levelHint === null ? undefined : levelHintId"
            @update:model-value="list.selectCategory(($event as string | null) ?? null)"
          />
          <div class="flex flex-col gap-1.5">
            <Label :for="levelFilterId">
              Poziom
            </Label>
            <Select
              :model-value="list.level.value ?? ALL_LEVELS"
              @update:model-value="list.selectLevel(LEVEL_FILTERS.find((filter) => filter.value === $event)?.value ?? null)"
            >
              <SelectTrigger
                :id="levelFilterId"
                data-testid="level-filter"
                class="w-44"
              >
                <SelectValue>{{ levelFilterLabel }}</SelectValue>
              </SelectTrigger>
              <SelectContent>
                <SelectItem :value="ALL_LEVELS">
                  Wszystkie
                </SelectItem>
                <SelectItem
                  v-for="filter in LEVEL_FILTERS"
                  :key="filter.value"
                  :value="filter.value"
                >
                  {{ filter.label }}
                </SelectItem>
              </SelectContent>
            </Select>
            <p
              :id="levelHintId"
              role="status"
              data-testid="level-hint"
              class="max-w-52 text-xs text-muted-foreground empty:hidden"
            >
              {{ levelHint }}
            </p>
          </div>
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
        label="Lista stanów magazynowych"
        :columns="columns"
        :rows="rows"
        :row-key="(row: StockRow) => row.variantId"
        row-testid="stock-row"
        :group-key="(row: StockRow) => row.productId"
        group-testid="product-row"
        error-testid="stock-error"
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
        </template>
        <template #cell-variant="{ row }">
          <span
            data-testid="variant-label"
            class="break-words"
          >{{ row.label === "" ? "Wariant domyślny" : row.label }}</span>
        </template>
        <template #cell-physical="{ row }">
          <span
            data-testid="physical"
            class="whitespace-nowrap"
          >{{ number(row.stock?.physical) }}</span>
        </template>
        <template #cell-reserved="{ row }">
          <span
            data-testid="reserved"
            class="whitespace-nowrap"
          >{{ number(row.stock?.reserved) }}</span>
        </template>
        <template #cell-available="{ row }">
          <span
            data-testid="available"
            class="font-bold whitespace-nowrap text-heading"
          >{{ number(row.stock?.available) }}</span>
        </template>
        <template #cell-level="{ row }">
          <Badge
            v-if="row.stock"
            data-testid="level"
            :data-matches-level="row.matchesLevel"
            :class="row.matchesLevel ? 'ring-2 ring-ring ring-offset-1' : undefined"
            :variant="levelLabel(row.stock.level).variant"
          >
            {{ levelLabel(row.stock.level).label }}<span
              v-if="row.matchesLevel"
              class="sr-only"
            >, pasuje do filtra</span>
          </Badge>
          <span
            v-else
            data-testid="level"
            :data-matches-level="row.matchesLevel"
          >—</span>
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

    <StockDialogs
      ref="dialogs"
      :rows="rows"
      :receive-stock="receiveStock"
      :correct-stock="correctStock"
      :fallback-focus="() => header?.heading ?? null"
    />
  </div>
</template>
