<script setup lang="ts">
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Skeleton from "primevue/skeleton";
import { formatMoney } from "@/prices/format";
import { MAX_SEARCH_LENGTH, type PriceRow, usePriceList } from "@/prices/usePriceList";
import { CURRENCY } from "./format";
import type { PromotionVariant } from "./usePromotionForm";

/**
 * Chooses variants from the price list (`GET /api/v1/admin/prices`): a search by name, a row per variant with its
 * catalog price, and the buttons to add one variant or all the variants of a product. A variant without a price is not
 * offered (the API refuses it in a promotion). What is added goes up as `add`; the parent decides what it does with it.
 */
const props = defineProps<{ chosen: string[] }>();
const emit = defineEmits<{ add: [variants: PromotionVariant[]]; close: [] }>();

const { search, searchTooShort, rows, loading, loaded, error, hasPrevious, hasNext, pageNumber, goNext, goPrevious, retry } = usePriceList();

function toVariant(row: PriceRow): PromotionVariant {
  return {
    variantId: row.variantId,
    productId: row.productId,
    productName: row.name,
    label: row.label,
    regularGross: row.price?.gross ?? null,
    promotionalGross: null,
  };
}

function addVariant(row: PriceRow): void {
  emit("add", [toVariant(row)]);
}

function addProduct(productId: string): void {
  emit(
    "add",
    rows.value.filter((row) => row.productId === productId && row.price !== null).map(toVariant),
  );
}

function isChosen(row: PriceRow): boolean {
  return props.chosen.includes(row.variantId);
}
</script>

<template>
  <section
    data-testid="picker"
    class="picker"
    aria-label="Wybór wariantów"
  >
    <div class="picker-header">
      <InputText
        v-model="search"
        data-testid="picker-search"
        type="search"
        :maxlength="MAX_SEARCH_LENGTH"
        placeholder="Szukaj produktu po nazwie"
        aria-label="Szukaj produktu po nazwie"
      />
      <Button
        data-testid="close-picker"
        label="Zamknij wybór"
        severity="secondary"
        @click="emit('close')"
      />
    </div>
    <small
      v-if="searchTooShort"
      class="hint"
    >Wpisz co najmniej 2 znaki, aby wyszukać.</small>

    <Message
      v-if="error"
      data-testid="picker-error"
      severity="error"
    >
      {{ error }}
      <Button
        data-testid="picker-retry"
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
      :value="rows"
      :loading="loading"
      data-key="variantId"
      :pt="{ bodyRow: { 'data-testid': 'picker-row' } }"
    >
      <template #empty>
        <span data-testid="picker-empty">Brak produktów spełniających kryteria.</span>
      </template>
      <Column header="Produkt">
        <template #body="{ data }">
          <span v-if="data.first">{{ data.name }}</span>
        </template>
      </Column>
      <Column header="Wariant">
        <template #body="{ data }">
          {{ data.label }}
        </template>
      </Column>
      <Column
        header="Cena katalogowa"
        class="amount"
      >
        <template #body="{ data }">
          {{ data.price ? formatMoney(data.price.gross, CURRENCY) : "Brak ceny" }}
        </template>
      </Column>
      <Column header="Dodaj">
        <template #body="{ data }">
          <div class="row-actions">
            <Button
              data-testid="add-variant"
              :label="isChosen(data) ? 'Dodano' : 'Dodaj wariant'"
              :icon="isChosen(data) ? 'pi pi-check' : 'pi pi-plus'"
              severity="secondary"
              size="small"
              :disabled="isChosen(data) || data.price === null"
              @click="addVariant(data)"
            />
            <Button
              v-if="data.first"
              data-testid="add-product"
              label="Dodaj cały produkt"
              icon="pi pi-plus-circle"
              severity="secondary"
              size="small"
              @click="addProduct(data.productId)"
            />
          </div>
        </template>
      </Column>
    </DataTable>
    <Skeleton
      v-else-if="loading"
      height="8rem"
    />

    <div
      v-if="loaded"
      class="pager"
    >
      <Button
        data-testid="picker-previous"
        label="Poprzednia"
        icon="pi pi-chevron-left"
        severity="secondary"
        size="small"
        :disabled="!hasPrevious || loading"
        @click="goPrevious()"
      />
      <span class="page-number">Strona {{ pageNumber }}</span>
      <Button
        data-testid="picker-next"
        label="Następna"
        icon="pi pi-chevron-right"
        icon-pos="right"
        severity="secondary"
        size="small"
        :disabled="!hasNext || loading"
        @click="goNext()"
      />
    </div>
  </section>
</template>

<style scoped>
.picker {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 1rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.picker-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}

.hint,
.page-number {
  color: var(--p-text-muted-color);
}

.amount {
  text-align: right;
  white-space: nowrap;
}

.row-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.pager {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 1rem;
}
</style>
