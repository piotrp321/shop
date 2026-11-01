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
import { formatMoney, formatRate } from "@/prices/format";
import { useCategoryTree } from "@/prices/useCategoryTree";
import { usePriceList } from "@/prices/usePriceList";

const { search, searchTooShort, items, loading, loaded, error, hasPrevious, hasNext, pageNumber, selectCategory, goNext, goPrevious, retry } =
  usePriceList();
const { nodes: categories, error: categoriesError } = useCategoryTree();

// TreeSelect (single selection) holds the chosen node as { [key]: true }, or null when nothing is chosen.
const selection = ref<Record<string, boolean> | null>(null);
watch(selection, (value) => selectCategory(value ? (Object.keys(value)[0] ?? null) : null));
</script>

<template>
  <div class="prices">
    <h1>Ceny</h1>

    <div class="filters">
      <div class="field">
        <InputText
          v-model="search"
          data-testid="search"
          type="search"
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
      data-testid="prices-error"
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
      :pt="{ bodyRow: { 'data-testid': 'price-row' } }"
    >
      <template #empty>
        <span data-testid="empty">Brak produktów spełniających kryteria.</span>
      </template>
      <Column header="Nazwa">
        <template #body="{ data }">
          <span data-testid="name">{{ data.name }}</span>
        </template>
      </Column>
      <Column header="Klasa">
        <template #body="{ data }">
          <span data-testid="tax-class">{{ data.taxClass }}</span>
        </template>
      </Column>
      <Column
        header="Brutto"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="gross">{{ data.price ? formatMoney(data.price.gross, data.price.currency) : "Brak ceny" }}</span>
        </template>
      </Column>
      <Column
        header="Netto"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="net">{{ data.price ? formatMoney(data.price.net, data.price.currency) : "—" }}</span>
        </template>
      </Column>
      <Column
        header="VAT"
        class="amount"
      >
        <template #body="{ data }">
          <span data-testid="vat">{{ data.price ? formatMoney(data.price.vat, data.price.currency) : "—" }}</span>
        </template>
      </Column>
      <Column header="Stawka">
        <template #body="{ data }">
          <span data-testid="vat-rate">{{ data.price ? formatRate(data.price.vatRate) : "—" }}</span>
          <Tag
            v-if="data.price?.vatRateOutdated"
            data-testid="outdated"
            severity="warn"
            icon="pi pi-exclamation-triangle"
            :value="`Nieaktualna, obecnie ${formatRate(data.applicableVatRate)}`"
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
.prices {
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
