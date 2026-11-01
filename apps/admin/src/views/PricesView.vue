<script setup lang="ts">
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Dialog from "primevue/dialog";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Skeleton from "primevue/skeleton";
import Tag from "primevue/tag";
import TreeSelect from "primevue/treeselect";
import { computed, ref, watch } from "vue";
import { formatMoney, formatRate } from "@/prices/format";
import { parseGross } from "@/prices/parseGross";
import { useCategoryTree } from "@/prices/useCategoryTree";
import { MAX_SEARCH_LENGTH, type PriceListItem, usePriceList } from "@/prices/usePriceList";
import { useAuthStore } from "@/stores/auth";

const {
  search,
  searchTooShort,
  items,
  loading,
  loaded,
  error,
  hasPrevious,
  hasNext,
  pageNumber,
  selectCategory,
  goNext,
  goPrevious,
  retry,
  changePrice,
} = usePriceList();
const { nodes: categories, error: categoriesError } = useCategoryTree();

// TreeSelect (single selection) holds the chosen node as { [key]: true }, or null when nothing is chosen.
const selection = ref<Record<string, boolean> | null>(null);
watch(selection, (value) => selectCategory(value ? (Object.keys(value)[0] ?? null) : null));

// Only hides the action: the API checks the permission on every change. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canChangePrices = computed(() => auth.staff?.roles.includes("admin") ?? false);

// The dialog follows the row by id, so after a conflict reloads the page it shows the current price and version.
const editingId = ref<string | null>(null);
const editingName = ref("");
const editing = computed(() => items.value.find((item) => item.productId === editingId.value) ?? null);
const grossText = ref("");
const fieldError = ref<string | null>(null);
const conflict = ref<string | null>(null);
const changeError = ref<string | null>(null);
const saving = ref(false);
const dialogVisible = computed({
  get: () => editingId.value !== null,
  set: (visible: boolean) => {
    if (!visible) {
      editingId.value = null;
    }
  },
});

function openChange(item: PriceListItem): void {
  editingId.value = item.productId;
  editingName.value = item.name;
  grossText.value = "";
  fieldError.value = null;
  conflict.value = null;
  changeError.value = null;
}

async function saveChange(): Promise<void> {
  const item = editing.value;
  if (item === null || saving.value) {
    return;
  }
  const gross = parseGross(grossText.value);
  if (!gross.ok) {
    fieldError.value = gross.message;
    return;
  }
  fieldError.value = null;
  conflict.value = null;
  changeError.value = null;
  saving.value = true;
  const result = await changePrice(item.productId, gross.grosze, item.price?.version ?? null);
  saving.value = false;
  switch (result.status) {
    case "saved":
      editingId.value = null;
      break;
    case "invalid":
      fieldError.value = result.message;
      break;
    case "conflict":
      conflict.value = result.message;
      break;
    case "failed":
      changeError.value = result.message;
      break;
  }
}
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
      <Column
        v-if="canChangePrices"
        header="Akcje"
      >
        <template #body="{ data }">
          <Button
            data-testid="change-price"
            label="Zmień cenę"
            icon="pi pi-pencil"
            severity="secondary"
            size="small"
            @click="openChange(data)"
          />
        </template>
      </Column>
    </DataTable>
    <Skeleton
      v-else-if="loading"
      height="16rem"
    />

    <Dialog
      v-model:visible="dialogVisible"
      modal
      header="Zmień cenę"
      :style="{ width: '28rem' }"
    >
      <div
        data-testid="change-dialog"
        class="change"
      >
        <strong>{{ editing?.name ?? editingName }}</strong>
        <span data-testid="current-gross">
          Obecna cena brutto: {{ editing?.price ? formatMoney(editing.price.gross, editing.price.currency) : "Brak ceny" }}
        </span>
        <span
          v-if="editing"
          data-testid="rate-preview"
          class="hint"
        >
          Stawka VAT: {{ formatRate(editing.applicableVatRate) }}. Netto i VAT wyliczy API.
        </span>
        <Message
          v-if="conflict"
          data-testid="change-conflict"
          severity="warn"
        >
          {{ conflict }}
        </Message>
        <Message
          v-if="changeError"
          data-testid="change-error"
          severity="error"
        >
          {{ changeError }}
        </Message>
        <div class="field">
          <label for="gross-input">Nowa cena brutto (zł)</label>
          <InputText
            id="gross-input"
            v-model="grossText"
            data-testid="gross-input"
            inputmode="decimal"
            autocomplete="off"
            placeholder="np. 54,90"
            :invalid="fieldError !== null"
            :aria-invalid="fieldError !== null"
            aria-describedby="gross-error"
            @keydown.enter.prevent="saveChange()"
          />
          <Message
            v-if="fieldError"
            id="gross-error"
            data-testid="gross-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ fieldError }}
          </Message>
        </div>
        <div class="actions">
          <Button
            data-testid="cancel-change"
            label="Anuluj"
            severity="secondary"
            @click="dialogVisible = false"
          />
          <Button
            data-testid="save-price"
            label="Zapisz"
            icon="pi pi-check"
            :loading="saving"
            :disabled="editing === null"
            @click="saveChange()"
          />
        </div>
      </div>
    </Dialog>

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

.change {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
</style>
