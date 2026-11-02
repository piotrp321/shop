<script setup lang="ts">
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Skeleton from "primevue/skeleton";
import Tag from "primevue/tag";
import TreeSelect from "primevue/treeselect";
import { ref, watch } from "vue";
import { useCategoryTree } from "@/composables/useCategoryTree";
import { levelLabel } from "@/inventory/format";
import { MAX_SEARCH_LENGTH, useStockList } from "@/inventory/useStockList";

const { search, searchTooShort, items, loading, loaded, error, hasPrevious, hasNext, pageNumber, selectCategory, goNext, goPrevious, retry } =
  useStockList();
const { nodes: categories, error: categoriesError } = useCategoryTree();

// TreeSelect (single selection) holds the chosen node as { [key]: true }, or null when nothing is chosen.
const selection = ref<Record<string, boolean> | null>(null);
watch(selection, (value) => selectCategory(value ? (Object.keys(value)[0] ?? null) : null));
</script>

<template>
  <div class="inventory">
    <h1>Stany magazynowe</h1>

    <div class="filters">
      <div class="field">
        <InputText
          v-model="search"
          data-testid="search"
          type="search"
          :maxlength="MAX_SEARCH_LENGTH"
          placeholder="Szukaj po nazwie"
          aria-label="Szukaj po nazwie produktu"
        />
        <small
          v-if="searchTooShort"
          data-testid="search-hint"
          class="hint"
        >
          Wpisz co najmniej 2 znaki, aby wyszukać.
        </small>
      </div>
      <TreeSelect
        v-model="selection"
        data-testid="category-filter"
        class="category-filter"
        :options="categories"
        selection-mode="single"
        placeholder="Wszystkie kategorie"
        show-clear
        filter
        :disabled="categories.length === 0"
        aria-label="Filtr kategorii"
      />
    </div>

    <Message
      v-if="categoriesError"
      data-testid="categories-error"
      severity="warn"
    >
      {{ categoriesError }}
    </Message>

    <Message
      v-if="error"
      data-testid="stock-error"
      severity="error"
    >
      {{ error }}
      <Button
        data-testid="retry"
        label="Spróbuj ponownie"
        icon="pi pi-refresh"
        severity="secondary"
        size="small"
        :loading="loading"
        @click="retry()"
      />
    </Message>

    <DataTable
      v-if="loaded"
      :value="items"
      :loading="loading"
      data-key="productId"
      :pt="{ bodyRow: { 'data-testid': 'stock-row' } }"
    >
      <template #empty>
        <span data-testid="empty">Brak produktów spełniających kryteria.</span>
      </template>
      <Column header="Nazwa">
        <template #body="{ data }">
          <span data-testid="name">{{ data.name }}</span>
        </template>
      </Column>
      <Column
        header="Fizyczny"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="physical">{{ data.stock?.physical ?? 0 }}</span>
        </template>
      </Column>
      <Column
        header="Zarezerwowany"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="reserved">{{ data.stock?.reserved ?? 0 }}</span>
        </template>
      </Column>
      <Column
        header="Dostępny"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="available">{{ data.stock?.available ?? 0 }}</span>
        </template>
      </Column>
      <Column header="Poziom">
        <template #body="{ data }">
          <Tag
            data-testid="level"
            :value="levelLabel(data.stock?.level ?? 'out_of_stock').label"
            :severity="levelLabel(data.stock?.level ?? 'out_of_stock').severity"
          />
        </template>
      </Column>
    </DataTable>
    <Skeleton
      v-else-if="loading"
      height="16rem"
    />

    <div
      v-if="loaded"
      class="pager"
    >
      <Button
        data-testid="previous-page"
        label="Poprzednia"
        icon="pi pi-chevron-left"
        severity="secondary"
        :disabled="!hasPrevious || loading"
        @click="goPrevious()"
      />
      <span
        data-testid="page-number"
        class="page-number"
      >Strona {{ pageNumber }}</span>
      <Button
        data-testid="next-page"
        label="Następna"
        icon="pi pi-chevron-right"
        icon-pos="right"
        severity="secondary"
        :disabled="!hasNext || loading"
        @click="goNext()"
      />
    </div>
  </div>
</template>

<style scoped>
.inventory {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 18rem;
}

.hint {
  color: var(--p-text-muted-color);
}

.category-filter {
  min-width: 18rem;
}

.amount {
  text-align: right;
  white-space: nowrap;
}

.pager {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 1rem;
}

.page-number {
  color: var(--p-text-muted-color);
}
</style>
