<script setup lang="ts">
import Button from "primevue/button";
import Column from "primevue/column";
import DataTable from "primevue/datatable";
import Dialog from "primevue/dialog";
import InputNumber from "primevue/inputnumber";
import InputText from "primevue/inputtext";
import Message from "primevue/message";
import Select from "primevue/select";
import Skeleton from "primevue/skeleton";
import Tag from "primevue/tag";
import Textarea from "primevue/textarea";
import TreeSelect from "primevue/treeselect";
import { computed, ref, watch } from "vue";
import { useCategoryTree } from "@/composables/useCategoryTree";
import { levelLabel } from "@/inventory/format";
import { MAX_SEARCH_LENGTH, type StockChange, type StockListItem, useStockList } from "@/inventory/useStockList";
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
  receiveStock,
  correctStock,
} = useStockList();
const { nodes: categories, error: categoriesError } = useCategoryTree();

// TreeSelect (single selection) holds the chosen node as { [key]: true }, or null when nothing is chosen.
const selection = ref<Record<string, boolean> | null>(null);
watch(selection, (value) => selectCategory(value ? (Object.keys(value)[0] ?? null) : null));

// Only hides the actions: the API checks the permission on every change. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canChangeStock = computed(() => auth.staff?.roles.some((role) => role === "admin" || role === "warehouse") ?? false);

const REASONS = [
  { label: "Inwentaryzacja", value: "inventory_count" },
  { label: "Uszkodzenie", value: "damage" },
  { label: "Zaginięcie", value: "loss" },
  { label: "Inne", value: "other" },
] as const;
type Reason = (typeof REASONS)[number]["value"];

// Each dialog counts its openings and closings. A save answers only the dialog it was sent from: when the dialog was
// closed or opened again meanwhile (even for the same product), the answer must not touch the new one.
let receiptSession = 0;
let correctionSession = 0;

// Receipt. The dialog follows the row by id, so a reload shows the current stock in it.
const receivingId = ref<string | null>(null);
const receivingName = ref("");
const receiving = computed(() => items.value.find((item) => item.productId === receivingId.value) ?? null);
const quantity = ref<number | null>(null);
const receiptNote = ref("");
// The idempotency key of the receipt, made when the dialog opens: repeating the same receipt (e.g. after the answer was
// lost) sends the same key, so the API adds the units once. A new dialog is a new receipt and gets a new key.
let receiptKey = "";
const quantityError = ref<string | null>(null);
const receiptNoteError = ref<string | null>(null);
const receiptMessage = ref<string | null>(null);
const receiptSaving = ref(false);
const receiptVisible = computed({
  get: () => receivingId.value !== null,
  set: (visible: boolean) => {
    if (!visible) {
      closeReceipt();
    }
  },
});

function closeReceipt(): void {
  receiptSession++;
  receivingId.value = null;
  receiptSaving.value = false;
}

function openReceipt(item: StockListItem): void {
  receiptSession++;
  receiptSaving.value = false;
  receiptKey = crypto.randomUUID();
  receivingId.value = item.productId;
  receivingName.value = item.name;
  quantity.value = null;
  receiptNote.value = "";
  quantityError.value = null;
  receiptNoteError.value = null;
  receiptMessage.value = null;
}

async function saveReceipt(): Promise<void> {
  const item = receiving.value;
  if (item === null || receiptSaving.value) {
    return;
  }
  quantityError.value = null;
  receiptNoteError.value = null;
  receiptMessage.value = null;
  if (quantity.value === null) {
    quantityError.value = "Podaj ilość przyjętych sztuk.";
    return;
  }
  receiptSaving.value = true;
  const session = receiptSession;
  const result = await receiveStock(item.productId, { quantity: quantity.value, note: receiptNote.value, key: receiptKey });
  if (session !== receiptSession) {
    // The list has taken the outcome (the saved stock); this dialog is not the one that asked.
    return;
  }
  receiptSaving.value = false;
  if (result.status === "saved") {
    closeReceipt();
    return;
  }
  quantityError.value = result.status === "invalid" ? (result.fields.quantity ?? null) : null;
  receiptNoteError.value = result.status === "invalid" ? (result.fields.note ?? null) : null;
  receiptMessage.value = result.message;
}

// Correction. The version sent is the one of the row at the moment of saving, so after a conflict reloads the row the
// next attempt is based on the current stock.
const correctingId = ref<string | null>(null);
const correctingName = ref("");
const correcting = computed(() => items.value.find((item) => item.productId === correctingId.value) ?? null);
const counted = ref<number | null>(null);
const reason = ref<Reason>("inventory_count");
const correctionNote = ref("");
const countedError = ref<string | null>(null);
const reasonError = ref<string | null>(null);
const correctionNoteError = ref<string | null>(null);
const correctionConflict = ref<string | null>(null);
const correctionMessage = ref<string | null>(null);
const correctionSaving = ref(false);
const correctionVisible = computed({
  get: () => correctingId.value !== null,
  set: (visible: boolean) => {
    if (!visible) {
      closeCorrection();
    }
  },
});

function closeCorrection(): void {
  correctionSession++;
  correctingId.value = null;
  correctionSaving.value = false;
}

function openCorrection(item: StockListItem): void {
  correctionSession++;
  correctionSaving.value = false;
  correctingId.value = item.productId;
  correctingName.value = item.name;
  counted.value = null;
  reason.value = "inventory_count";
  correctionNote.value = "";
  countedError.value = null;
  reasonError.value = null;
  correctionNoteError.value = null;
  correctionConflict.value = null;
  correctionMessage.value = null;
}

async function saveCorrection(): Promise<void> {
  const item = correcting.value;
  if (item === null || correctionSaving.value) {
    return;
  }
  countedError.value = null;
  reasonError.value = null;
  correctionNoteError.value = null;
  correctionConflict.value = null;
  correctionMessage.value = null;
  if (counted.value === null) {
    countedError.value = "Podaj stan policzony.";
    return;
  }
  // The API requires the note for "Inne" too; checking it here only saves a round trip.
  if (reason.value === "other" && correctionNote.value.trim() === "") {
    correctionNoteError.value = "Przy powodzie „Inne” podaj notatkę.";
    return;
  }
  correctionSaving.value = true;
  const session = correctionSession;
  const result = await correctStock(item.productId, {
    physical: counted.value,
    reason: reason.value,
    note: correctionNote.value,
    version: item.stock?.version ?? null,
  });
  if (session !== correctionSession) {
    return;
  }
  correctionSaving.value = false;
  applyCorrectionResult(result);
}

function applyCorrectionResult(result: StockChange): void {
  switch (result.status) {
    case "saved":
      closeCorrection();
      break;
    case "invalid":
      countedError.value = result.fields.physical ?? null;
      reasonError.value = result.fields.reason ?? null;
      correctionNoteError.value = result.fields.note ?? null;
      correctionMessage.value = result.message;
      break;
    case "conflict":
      correctionConflict.value = result.message;
      break;
    case "failed":
      correctionMessage.value = result.message;
      break;
  }
}
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
            v-if="data.stock"
            data-testid="level"
            :value="levelLabel(data.stock.level).label"
            :severity="levelLabel(data.stock.level).severity"
          />
          <span
            v-else
            data-testid="level"
          >—</span>
        </template>
      </Column>
      <Column
        v-if="canChangeStock"
        header="Akcje"
      >
        <template #body="{ data }">
          <div class="row-actions">
            <Button
              data-testid="receive-stock"
              label="Przyjęcie"
              icon="pi pi-plus"
              severity="secondary"
              size="small"
              @click="openReceipt(data)"
            />
            <Button
              data-testid="correct-stock"
              label="Korekta"
              icon="pi pi-pencil"
              severity="secondary"
              size="small"
              @click="openCorrection(data)"
            />
          </div>
        </template>
      </Column>
    </DataTable>
    <Skeleton
      v-else-if="loading"
      height="16rem"
    />

    <Dialog
      v-model:visible="receiptVisible"
      modal
      header="Przyjęcie"
      :style="{ width: '28rem' }"
    >
      <div
        data-testid="receive-dialog"
        class="change"
      >
        <strong>{{ receiving?.name ?? receivingName }}</strong>
        <span
          v-if="receiving"
          class="hint"
        >
          Stan fizyczny: {{ receiving.stock?.physical ?? 0 }}. Nowy stan wyliczy API.
        </span>
        <Message
          v-if="receiptMessage"
          data-testid="receive-error"
          severity="error"
        >
          {{ receiptMessage }}
        </Message>
        <div class="field">
          <label for="receive-quantity">Przyjęta ilość (szt.)</label>
          <InputNumber
            v-model="quantity"
            input-id="receive-quantity"
            :use-grouping="false"
            :max-fraction-digits="0"
            :invalid="quantityError !== null"
            aria-describedby="receive-quantity-error"
            @keydown.enter.prevent="saveReceipt()"
          />
          <Message
            v-if="quantityError"
            id="receive-quantity-error"
            data-testid="receive-quantity-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ quantityError }}
          </Message>
        </div>
        <div class="field">
          <label for="receive-note">Notatka (opcjonalnie)</label>
          <Textarea
            id="receive-note"
            v-model="receiptNote"
            data-testid="receive-note"
            rows="3"
            :invalid="receiptNoteError !== null"
            aria-describedby="receive-note-error"
          />
          <Message
            v-if="receiptNoteError"
            id="receive-note-error"
            data-testid="receive-note-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ receiptNoteError }}
          </Message>
        </div>
        <div class="actions">
          <Button
            data-testid="receive-cancel"
            label="Anuluj"
            severity="secondary"
            @click="receiptVisible = false"
          />
          <Button
            data-testid="receive-submit"
            label="Przyjmij"
            icon="pi pi-check"
            :loading="receiptSaving"
            :disabled="receiving === null"
            @click="saveReceipt()"
          />
        </div>
      </div>
    </Dialog>

    <Dialog
      v-model:visible="correctionVisible"
      modal
      header="Korekta"
      :style="{ width: '28rem' }"
    >
      <div
        data-testid="correct-dialog"
        class="change"
      >
        <strong>{{ correcting?.name ?? correctingName }}</strong>
        <span
          v-if="correcting"
          class="hint"
        >
          Stan fizyczny: {{ correcting.stock?.physical ?? 0 }}, zarezerwowany: {{ correcting.stock?.reserved ?? 0 }}.
        </span>
        <Message
          v-if="correctionConflict"
          data-testid="correct-conflict"
          severity="warn"
        >
          {{ correctionConflict }}
        </Message>
        <Message
          v-if="correctionMessage"
          data-testid="correct-error"
          severity="error"
        >
          {{ correctionMessage }}
        </Message>
        <div class="field">
          <label for="correct-physical">Stan policzony (szt.)</label>
          <InputNumber
            v-model="counted"
            input-id="correct-physical"
            :use-grouping="false"
            :max-fraction-digits="0"
            :invalid="countedError !== null"
            aria-describedby="correct-physical-error"
            @keydown.enter.prevent="saveCorrection()"
          />
          <Message
            v-if="countedError"
            id="correct-physical-error"
            data-testid="correct-physical-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ countedError }}
          </Message>
        </div>
        <div class="field">
          <label for="correct-reason">Powód</label>
          <Select
            v-model="reason"
            input-id="correct-reason"
            :options="[...REASONS]"
            option-label="label"
            option-value="value"
            :invalid="reasonError !== null"
          />
          <Message
            v-if="reasonError"
            data-testid="correct-reason-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ reasonError }}
          </Message>
        </div>
        <div class="field">
          <label for="correct-note">{{ reason === "other" ? "Notatka (wymagana)" : "Notatka (opcjonalnie)" }}</label>
          <Textarea
            id="correct-note"
            v-model="correctionNote"
            data-testid="correct-note"
            rows="3"
            :invalid="correctionNoteError !== null"
            aria-describedby="correct-note-error"
          />
          <Message
            v-if="correctionNoteError"
            id="correct-note-error"
            data-testid="correct-note-error"
            severity="error"
            size="small"
            variant="simple"
          >
            {{ correctionNoteError }}
          </Message>
        </div>
        <div class="actions">
          <Button
            data-testid="correct-cancel"
            label="Anuluj"
            severity="secondary"
            @click="correctionVisible = false"
          />
          <Button
            data-testid="correct-submit"
            label="Zapisz korektę"
            icon="pi pi-check"
            :loading="correctionSaving"
            :disabled="correcting === null"
            @click="saveCorrection()"
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

.row-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.change {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.change .field {
  min-width: 0;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
</style>
