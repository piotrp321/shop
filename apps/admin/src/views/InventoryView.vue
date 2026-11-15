<script setup lang="ts">
import { ClipboardPen, History, PackagePlus } from "lucide-vue-next";
import { computed, nextTick, ref } from "vue";
import { toast } from "vue-sonner";
import CategoryCombobox from "@/components/CategoryCombobox.vue";
import FormField from "@/components/FormField.vue";
import DataTable, { type ListColumn } from "@/components/list/DataTable.vue";
import ListEmpty from "@/components/list/ListEmpty.vue";
import ListPager from "@/components/list/ListPager.vue";
import ListToolbar from "@/components/list/ListToolbar.vue";
import RowActions, { type RowAction } from "@/components/list/RowActions.vue";
import PageHeader from "@/components/PageHeader.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { useCategoryTree } from "@/composables/useCategoryTree";
import { focusAfterAction } from "@/lib/focus";
import { formatDelta, levelLabel, movementReasonLabel, movementSourceLabel, movementTypeLabel } from "@/inventory/format";
import { parseCount } from "@/inventory/parseCount";
import { type StockHistoryItem, useStockHistory } from "@/inventory/useStockHistory";
import { EMPTY_MESSAGE, MAX_SEARCH_LENGTH, type StockChange, type StockRow, useStockList } from "@/inventory/useStockList";
import { formatDateTime } from "@/prices/format";
import { useAuthStore } from "@/stores/auth";

const categories = useCategoryTree();
const list = useStockList({ nodes: categories.nodes, loaded: categories.loaded });
const { search, searchHint, query, filtered, items, rows, loading, loaded, error, forbidden, hasPrevious, hasNext, pageNumber, range, total, receiveStock, correctStock } = list;
const history = useStockHistory();

// Only hides the actions: the API checks the permission on every change. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canChangeStock = computed(() => auth.staff?.roles.some((role) => role === "admin" || role === "warehouse") ?? false);

const header = ref<InstanceType<typeof PageHeader> | null>(null);
const toolbar = ref<InstanceType<typeof ListToolbar> | null>(null);

// „Wyczyść filtry” goes away with the filters, so the focus goes to the search field.
async function clearFilters() {
  list.clearFilters();
  await nextTick();
  toolbar.value?.focusSearch();
}

const REASONS = [
  { label: "Inwentaryzacja", value: "inventory_count" },
  { label: "Uszkodzenie", value: "damage" },
  { label: "Zaginięcie", value: "loss" },
  { label: "Inne", value: "other" },
] as const;
type Reason = (typeof REASONS)[number]["value"];

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

// The name of a variant in the windows, the toasts and the names of the buttons: the product, and the label unless it is the default variant.
function variantName(row: StockRow): string {
  return row.label === "" ? row.name : `${row.name} / ${row.label}`;
}

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
    openReceipt(row, trigger);
  } else if (key === "correct") {
    openCorrection(row, trigger);
  } else {
    openHistory(row, trigger);
  }
}

function elementOf(target: { $el: unknown } | null): HTMLElement | undefined {
  return (target?.$el as HTMLElement | undefined) ?? undefined;
}

// Each window counts its openings and closings. A save answers only the window it was sent from: when the window was
// closed or opened again meanwhile (even for the same variant), the answer must not touch the new one.
let receiptSession = 0;
let correctionSession = 0;

// Receipt. The window follows the row by variant id, so a reload shows the current stock in it.
const receivingId = ref<string | null>(null);
const receivingName = ref("");
const receiving = computed(() => rows.value.find((row) => row.variantId === receivingId.value) ?? null);
const quantityText = ref("");
const receiptNote = ref("");
// The idempotency key of the receipt, made when the window opens: repeating the same receipt (e.g. after the answer was
// lost) sends the same key, so the API adds the units once. A new window is a new receipt and gets a new key.
let receiptKey = "";
const quantityError = ref<string | null>(null);
const receiptNoteError = ref<string | null>(null);
const receiptMessage = ref<string | null>(null);
const receiptSaving = ref(false);
const quantityInput = ref<InstanceType<typeof Input> | null>(null);
const receiptNoteInput = ref<InstanceType<typeof Textarea> | null>(null);
const receiptAlert = ref<InstanceType<typeof Alert> | null>(null);
// The button that opened the window: the focus goes back to it when the window closes.
let receiptOpener: HTMLElement | null = null;
const receiptOpen = computed({
  get: () => receivingId.value !== null,
  set: (open: boolean) => {
    if (!open) {
      closeReceipt();
    }
  },
});

function closeReceipt(): void {
  receiptSession++;
  receivingId.value = null;
  receiptSaving.value = false;
}

function openReceipt(row: StockRow, trigger: HTMLElement): void {
  receiptSession++;
  receiptSaving.value = false;
  receiptKey = crypto.randomUUID();
  receiptOpener = trigger;
  receivingId.value = row.variantId;
  receivingName.value = variantName(row);
  quantityText.value = "";
  receiptNote.value = "";
  quantityError.value = null;
  receiptNoteError.value = null;
  receiptMessage.value = null;
}

// The window opens on its first field, however it was opened (also with the Enter key, #273).
function focusQuantity(event: Event): void {
  event.preventDefault();
  elementOf(quantityInput.value)?.focus();
}

function returnReceiptFocus(event: Event): void {
  event.preventDefault();
  focusAfterAction(receiptOpener, header.value?.heading ?? null);
  receiptOpener = null;
}

async function saveReceipt(): Promise<void> {
  const item = receiving.value;
  if (item === null || receiptSaving.value) {
    return;
  }
  quantityError.value = null;
  receiptNoteError.value = null;
  receiptMessage.value = null;
  const count = parseCount(quantityText.value, "Podaj ilość przyjętych sztuk.");
  if (!count.ok) {
    quantityError.value = count.message;
    await focusReceiptProblem();
    return;
  }
  receiptSaving.value = true;
  const session = receiptSession;
  const name = variantName(item);
  const result = await receiveStock(item.variantId, { quantity: count.value, note: receiptNote.value, key: receiptKey });
  if (session !== receiptSession) {
    // The list has taken the outcome (the saved stock); this window is not the one that asked.
    return;
  }
  receiptSaving.value = false;
  if (result.status === "saved") {
    closeReceipt();
    toast.success(`Przyjęto ${count.value} szt.: ${name}`);
    return;
  }
  quantityError.value = result.status === "invalid" ? (result.fields.quantity ?? null) : null;
  receiptNoteError.value = result.status === "invalid" ? (result.fields.note ?? null) : null;
  receiptMessage.value = result.message;
  await focusReceiptProblem();
}

// After a failed save the focus goes to what says why: the field that the API names, otherwise the message.
async function focusReceiptProblem(): Promise<void> {
  await nextTick();
  const target = quantityError.value !== null ? quantityInput.value : receiptNoteError.value !== null ? receiptNoteInput.value : receiptAlert.value;
  elementOf(target)?.focus();
}

// Correction. The version sent is the one of the row at the moment of saving, so after a conflict reloads the row the
// next attempt is based on the current stock.
const correctingId = ref<string | null>(null);
const correctingName = ref("");
const correcting = computed(() => rows.value.find((row) => row.variantId === correctingId.value) ?? null);
const countedText = ref("");
const reason = ref<Reason>("inventory_count");
const reasonName = computed(() => REASONS.find((option) => option.value === reason.value)?.label ?? "");
const correctionNote = ref("");
const countedError = ref<string | null>(null);
const reasonError = ref<string | null>(null);
const correctionNoteError = ref<string | null>(null);
const correctionConflict = ref<string | null>(null);
const correctionMessage = ref<string | null>(null);
const correctionSaving = ref(false);
const countedInput = ref<InstanceType<typeof Input> | null>(null);
const reasonTrigger = ref<InstanceType<typeof SelectTrigger> | null>(null);
const correctionNoteInput = ref<InstanceType<typeof Textarea> | null>(null);
const correctionConflictAlert = ref<InstanceType<typeof Alert> | null>(null);
const correctionAlert = ref<InstanceType<typeof Alert> | null>(null);
let correctionOpener: HTMLElement | null = null;
const correctionOpen = computed({
  get: () => correctingId.value !== null,
  set: (open: boolean) => {
    if (!open) {
      closeCorrection();
    }
  },
});

function closeCorrection(): void {
  correctionSession++;
  correctingId.value = null;
  correctionSaving.value = false;
}

function openCorrection(row: StockRow, trigger: HTMLElement): void {
  correctionSession++;
  correctionSaving.value = false;
  correctionOpener = trigger;
  correctingId.value = row.variantId;
  correctingName.value = variantName(row);
  countedText.value = "";
  reason.value = "inventory_count";
  correctionNote.value = "";
  countedError.value = null;
  reasonError.value = null;
  correctionNoteError.value = null;
  correctionConflict.value = null;
  correctionMessage.value = null;
}

function focusCounted(event: Event): void {
  event.preventDefault();
  elementOf(countedInput.value)?.focus();
}

function returnCorrectionFocus(event: Event): void {
  event.preventDefault();
  focusAfterAction(correctionOpener, header.value?.heading ?? null);
  correctionOpener = null;
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
  const count = parseCount(countedText.value, "Podaj stan policzony.");
  if (!count.ok) {
    countedError.value = count.message;
    await focusCorrectionProblem();
    return;
  }
  // The API requires the note for "Inne" too; checking it here only saves a round trip.
  if (reason.value === "other" && correctionNote.value.trim() === "") {
    correctionNoteError.value = "Przy powodzie „Inne” podaj notatkę.";
    await focusCorrectionProblem();
    return;
  }
  correctionSaving.value = true;
  const session = correctionSession;
  const name = variantName(item);
  const result = await correctStock(item.variantId, {
    physical: count.value,
    reason: reason.value,
    note: correctionNote.value,
    version: item.stock?.version ?? null,
  });
  if (session !== correctionSession) {
    return;
  }
  correctionSaving.value = false;
  if (result.status === "saved") {
    closeCorrection();
    toast.success(`Stan skorygowany: ${name}`);
    return;
  }
  showCorrectionFailure(result);
  await focusCorrectionProblem();
}

function showCorrectionFailure(result: Exclude<StockChange, { status: "saved" }>): void {
  switch (result.status) {
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

async function focusCorrectionProblem(): Promise<void> {
  await nextTick();
  const target =
    countedError.value !== null
      ? countedInput.value
      : reasonError.value !== null
        ? reasonTrigger.value
        : correctionNoteError.value !== null
          ? correctionNoteInput.value
          : correctionConflict.value !== null
            ? correctionConflictAlert.value
            : correctionAlert.value;
  elementOf(target)?.focus();
}

// The history window is open while a variant is chosen. Its name is kept from the moment it was opened, since the list
// may move to another page meanwhile.
const historyName = ref("");
let historyOpener: HTMLElement | null = null;
const historyOpen = computed({
  get: () => history.variantId.value !== null,
  set: (open: boolean) => {
    if (!open) {
      history.close();
    }
  },
});

function openHistory(row: StockRow, trigger: HTMLElement): void {
  historyName.value = variantName(row);
  historyOpener = trigger;
  void history.open(row.variantId);
}

function returnHistoryFocus(event: Event): void {
  event.preventDefault();
  focusAfterAction(historyOpener, header.value?.heading ?? null);
  historyOpener = null;
}

// „Pokaż więcej” and „Spróbuj ponownie” go away with what they fetched; the focus then goes to „Zamknij” of the window.
const closeHistoryButton = ref<InstanceType<typeof Button> | null>(null);

async function historyAction(trigger: HTMLElement, action: () => Promise<void>): Promise<void> {
  await action();
  await nextTick();
  focusAfterAction(trigger, elementOf(closeHistoryButton.value) ?? null);
}

type HistoryRow = StockHistoryItem & { key: string };
const historyRows = computed<HistoryRow[]>(() => history.items.value.map((entry, index) => ({ ...entry, key: String(index) })));
const historyColumns: ListColumn<HistoryRow>[] = [
  { key: "occurredAt", header: "Data", main: true },
  { key: "type", header: "Rodzaj" },
  { key: "physicalDelta", header: "Zmiana fizycznego", align: "right" },
  { key: "physicalAfter", header: "Stan fizyczny po zmianie", align: "right" },
  { key: "reservedDelta", header: "Zmiana zarezerwowanego", align: "right", hideBelow: "lg", text: (row) => formatDelta(row.reservedDelta) },
  { key: "reservedAfter", header: "Stan zarezerwowanego po zmianie", align: "right", hideBelow: "lg", text: (row) => String(row.reservedAfter) },
  { key: "reason", header: "Powód", hideBelow: "lg", text: (row) => movementReasonLabel(row.reason) },
  { key: "note", header: "Notatka", hideBelow: "lg", text: (row) => row.note ?? "—" },
  { key: "reference", header: "Referencja", hideBelow: "lg", text: (row) => row.reference ?? "—" },
  { key: "source", header: "Źródło", hideBelow: "lg", text: (row) => movementSourceLabel(row.source) },
  { key: "actor", header: "Pracownik", hideBelow: "lg", text: (row) => row.actorName ?? "—" },
];

const DIALOG_CLASS = "max-h-[calc(100dvh-2rem)] max-w-none overflow-y-auto sm:max-w-none";
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
            :variant="levelLabel(row.stock.level).variant"
          >
            {{ levelLabel(row.stock.level).label }}
          </Badge>
          <span
            v-else
            data-testid="level"
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

    <Dialog v-model:open="receiptOpen">
      <DialogContent
        data-testid="receive-dialog"
        :class="`${DIALOG_CLASS} w-[min(28rem,calc(100vw-2rem))]`"
        @open-auto-focus="focusQuantity"
        @close-auto-focus="returnReceiptFocus"
      >
        <DialogHeader>
          <DialogTitle>Przyjęcie</DialogTitle>
          <DialogDescription class="font-semibold text-foreground">
            {{ receiving ? variantName(receiving) : receivingName }}
          </DialogDescription>
        </DialogHeader>
        <p
          v-if="receiving"
          class="text-sm text-muted-foreground"
        >
          Stan fizyczny: {{ receiving.stock?.physical ?? 0 }}. Nowy stan wyliczy API.
        </p>
        <Alert
          v-if="receiptMessage"
          ref="receiptAlert"
          data-testid="receive-error"
          variant="destructive"
          tabindex="-1"
        >
          <AlertDescription>{{ receiptMessage }}</AlertDescription>
        </Alert>
        <FormField
          control-id="receive-quantity"
          label="Przyjęta ilość (szt.)"
          :errors="quantityError ? [quantityError] : []"
          error-testid="receive-quantity-error"
        >
          <template #default="{ describedBy, invalid }">
            <Input
              id="receive-quantity"
              ref="quantityInput"
              v-model="quantityText"
              inputmode="numeric"
              autocomplete="off"
              :aria-invalid="invalid"
              :aria-describedby="describedBy"
              @keydown.enter.prevent="saveReceipt()"
            />
          </template>
        </FormField>
        <FormField
          control-id="receive-note"
          label="Notatka (opcjonalnie)"
          :errors="receiptNoteError ? [receiptNoteError] : []"
          error-testid="receive-note-error"
        >
          <template #default="{ describedBy, invalid }">
            <Textarea
              id="receive-note"
              ref="receiptNoteInput"
              v-model="receiptNote"
              data-testid="receive-note"
              rows="3"
              :aria-invalid="invalid"
              :aria-describedby="describedBy"
            />
          </template>
        </FormField>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            data-testid="receive-cancel"
            @click="receiptOpen = false"
          >
            Anuluj
          </Button>
          <Button
            type="button"
            variant="interface"
            data-testid="receive-submit"
            :disabled="receiving === null || receiptSaving"
            @click="saveReceipt()"
          >
            Przyjmij
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog v-model:open="correctionOpen">
      <DialogContent
        data-testid="correct-dialog"
        :class="`${DIALOG_CLASS} w-[min(28rem,calc(100vw-2rem))]`"
        @open-auto-focus="focusCounted"
        @close-auto-focus="returnCorrectionFocus"
      >
        <DialogHeader>
          <DialogTitle>Korekta</DialogTitle>
          <DialogDescription class="font-semibold text-foreground">
            {{ correcting ? variantName(correcting) : correctingName }}
          </DialogDescription>
        </DialogHeader>
        <p
          v-if="correcting"
          class="text-sm text-muted-foreground"
        >
          Stan fizyczny: {{ correcting.stock?.physical ?? 0 }}, zarezerwowany: {{ correcting.stock?.reserved ?? 0 }}.
        </p>
        <Alert
          v-if="correctionConflict"
          ref="correctionConflictAlert"
          data-testid="correct-conflict"
          tabindex="-1"
        >
          <AlertDescription>{{ correctionConflict }}</AlertDescription>
        </Alert>
        <Alert
          v-if="correctionMessage"
          ref="correctionAlert"
          data-testid="correct-error"
          variant="destructive"
          tabindex="-1"
        >
          <AlertDescription>{{ correctionMessage }}</AlertDescription>
        </Alert>
        <FormField
          control-id="correct-physical"
          label="Stan policzony (szt.)"
          :errors="countedError ? [countedError] : []"
          error-testid="correct-physical-error"
        >
          <template #default="{ describedBy, invalid }">
            <Input
              id="correct-physical"
              ref="countedInput"
              v-model="countedText"
              inputmode="numeric"
              autocomplete="off"
              :aria-invalid="invalid"
              :aria-describedby="describedBy"
              @keydown.enter.prevent="saveCorrection()"
            />
          </template>
        </FormField>
        <FormField
          control-id="correct-reason"
          label="Powód"
          :errors="reasonError ? [reasonError] : []"
          error-testid="correct-reason-error"
        >
          <template #default="{ describedBy, invalid }">
            <Select
              :model-value="reason"
              @update:model-value="reason = String($event) as Reason"
            >
              <SelectTrigger
                id="correct-reason"
                ref="reasonTrigger"
                data-testid="correct-reason"
                class="w-full"
                :aria-invalid="invalid ? 'true' : undefined"
                :aria-describedby="describedBy"
              >
                <SelectValue>{{ reasonName }}</SelectValue>
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="option in REASONS"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </SelectItem>
              </SelectContent>
            </Select>
          </template>
        </FormField>
        <FormField
          control-id="correct-note"
          :label="reason === 'other' ? 'Notatka (wymagana)' : 'Notatka (opcjonalnie)'"
          :errors="correctionNoteError ? [correctionNoteError] : []"
          error-testid="correct-note-error"
        >
          <template #default="{ describedBy, invalid }">
            <Textarea
              id="correct-note"
              ref="correctionNoteInput"
              v-model="correctionNote"
              data-testid="correct-note"
              rows="3"
              :aria-invalid="invalid"
              :aria-describedby="describedBy"
            />
          </template>
        </FormField>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            data-testid="correct-cancel"
            @click="correctionOpen = false"
          >
            Anuluj
          </Button>
          <Button
            type="button"
            variant="interface"
            data-testid="correct-submit"
            :disabled="correcting === null || correctionSaving"
            @click="saveCorrection()"
          >
            Zapisz korektę
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>

    <Dialog v-model:open="historyOpen">
      <DialogContent
        data-testid="history-dialog"
        :class="`${DIALOG_CLASS} w-[min(56rem,calc(100vw-2rem))]`"
        @close-auto-focus="returnHistoryFocus"
      >
        <DialogHeader>
          <DialogTitle>Historia stanu</DialogTitle>
          <DialogDescription class="font-semibold text-foreground">
            {{ historyName }}
          </DialogDescription>
        </DialogHeader>
        <Alert
          v-if="history.error.value"
          data-testid="history-error"
          variant="destructive"
          class="flex flex-wrap items-center gap-3"
        >
          <AlertDescription>{{ history.error.value }}</AlertDescription>
          <Button
            type="button"
            variant="outline"
            size="sm"
            data-testid="history-retry"
            @click="historyAction($event.currentTarget as HTMLElement, history.retry)"
          >
            Spróbuj ponownie
          </Button>
        </Alert>
        <DataTable
          label="Historia stanu"
          class="min-w-0"
          :columns="historyColumns"
          :rows="historyRows"
          :row-key="(row: HistoryRow) => row.key"
          row-testid="history-row"
          :loading="history.loading.value"
          :loaded="history.loaded.value"
          :error="null"
          :forbidden="false"
          :retry="async () => {}"
        >
          <template #cell-occurredAt="{ row }">
            <span data-testid="history-occurred-at">{{ formatDateTime(row.occurredAt) }}</span>
          </template>
          <template #cell-type="{ row }">
            <span data-testid="history-type">{{ movementTypeLabel(row.type) }}</span>
          </template>
          <template #cell-physicalDelta="{ row }">
            <span
              data-testid="history-physical-delta"
              class="whitespace-nowrap"
            >{{ formatDelta(row.physicalDelta) }}</span>
          </template>
          <template #cell-physicalAfter="{ row }">
            <span
              data-testid="history-physical-after"
              class="whitespace-nowrap"
            >{{ row.physicalAfter }}</span>
          </template>
          <template #cell-reservedDelta="{ row }">
            <span
              data-testid="history-reserved-delta"
              class="whitespace-nowrap"
            >{{ formatDelta(row.reservedDelta) }}</span>
          </template>
          <template #cell-reservedAfter="{ row }">
            <span
              data-testid="history-reserved-after"
              class="whitespace-nowrap"
            >{{ row.reservedAfter }}</span>
          </template>
          <template #cell-reason="{ row }">
            <span data-testid="history-reason">{{ movementReasonLabel(row.reason) }}</span>
          </template>
          <template #cell-note="{ row }">
            <span
              data-testid="history-note"
              class="break-words"
            >{{ row.note ?? "—" }}</span>
          </template>
          <template #cell-reference="{ row }">
            <span
              data-testid="history-reference"
              class="break-words"
            >{{ row.reference ?? "—" }}</span>
          </template>
          <template #cell-source="{ row }">
            <span data-testid="history-source">{{ movementSourceLabel(row.source) }}</span>
          </template>
          <template #cell-actor="{ row }">
            <span data-testid="history-actor">{{ row.actorName ?? "—" }}</span>
          </template>
          <template #empty>
            <p
              data-testid="history-empty"
              class="px-4 py-8 text-center text-sm text-muted-foreground"
            >
              Produkt nie ma jeszcze zmian stanu.
            </p>
          </template>
        </DataTable>
        <DialogFooter>
          <Button
            v-if="history.hasMore.value"
            type="button"
            variant="outline"
            data-testid="history-more"
            :aria-busy="history.loading.value"
            @click="historyAction($event.currentTarget as HTMLElement, history.loadMore)"
          >
            Pokaż więcej
          </Button>
          <Button
            ref="closeHistoryButton"
            type="button"
            variant="interface"
            data-testid="close-history"
            @click="historyOpen = false"
          >
            Zamknij
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
