<script setup lang="ts">
import { History, Pencil, TriangleAlert } from "lucide-vue-next";
import { computed, nextTick, onMounted, ref } from "vue";
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
import { useCategoryTree } from "@/composables/useCategoryTree";
import { focusAfterAction } from "@/lib/focus";
import { formatDateTime, formatMoney, formatRate, sourceLabel } from "@/prices/format";
import { parseGross } from "@/prices/parseGross";
import type { PriceHistoryItem } from "@/prices/usePriceHistory";
import { usePriceHistory } from "@/prices/usePriceHistory";
import { EMPTY_MESSAGE, MAX_SEARCH_LENGTH, type PriceRow, usePriceList } from "@/prices/usePriceList";
import { fetchTaxClasses, type TaxClass, taxClassName } from "@/products/taxClasses";
import { useAuthStore } from "@/stores/auth";

const categories = useCategoryTree();
const list = usePriceList({ nodes: categories.nodes, loaded: categories.loaded });
const { search, searchHint, query, filtered, items, rows, loading, loaded, error, forbidden, hasPrevious, hasNext, pageNumber, range, changePrice } = list;
const history = usePriceHistory();

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

// The name of a variant in the dialogs, the toast and the names of the buttons: the product, and the label unless it is the default variant.
function variantName(row: PriceRow): string {
  return row.label === "" ? row.name : `${row.name} / ${row.label}`;
}

const rowActions = computed<RowAction[]>(() => [
  ...(canChangePrices.value ? [{ key: "change", label: "Zmień cenę", icon: Pencil, testid: "change-price" }] : []),
  { key: "history", label: "Historia", icon: History, testid: "show-history" },
]);

function onAction(key: string, row: PriceRow, trigger: HTMLElement): void {
  if (key === "change") {
    openChange(row, trigger);
  } else {
    openHistory(row, trigger);
  }
}

// The dialog follows the row by variant id, so after a conflict refreshes the page it shows the current price and version.
const editingId = ref<string | null>(null);
const editingName = ref("");
const editing = computed(() => rows.value.find((row) => row.variantId === editingId.value) ?? null);
const grossText = ref("");
const fieldError = ref<string | null>(null);
const conflict = ref<string | null>(null);
const changeError = ref<string | null>(null);
const saving = ref(false);
const grossInput = ref<InstanceType<typeof Input> | null>(null);
const conflictAlert = ref<InstanceType<typeof Alert> | null>(null);
const errorAlert = ref<InstanceType<typeof Alert> | null>(null);
// The button that opened the window: the focus goes back to it when the window closes.
let changeOpener: HTMLElement | null = null;
// Counts the openings and closings of the dialog. A save answers only the dialog it was sent from: when the dialog
// was closed or opened again meanwhile (even for the same variant), the answer must not touch the new one.
let dialogSession = 0;
const dialogOpen = computed({
  get: () => editingId.value !== null,
  set: (open: boolean) => {
    if (!open) {
      closeChange();
    }
  },
});

function closeChange(): void {
  dialogSession++;
  editingId.value = null;
  saving.value = false;
}

function openChange(row: PriceRow, trigger: HTMLElement): void {
  dialogSession++;
  saving.value = false;
  changeOpener = trigger;
  editingId.value = row.variantId;
  editingName.value = variantName(row);
  grossText.value = "";
  fieldError.value = null;
  conflict.value = null;
  changeError.value = null;
}

// The window opens on the field of the price, however it was opened (also with the Enter key, #273).
function focusGross(event: Event): void {
  event.preventDefault();
  (grossInput.value?.$el as HTMLElement | undefined)?.focus();
}

function returnChangeFocus(event: Event): void {
  event.preventDefault();
  focusAfterAction(changeOpener, header.value?.heading ?? null);
  changeOpener = null;
}

async function saveChange(): Promise<void> {
  const item = editing.value;
  if (item === null || saving.value) {
    return;
  }
  const gross = parseGross(grossText.value);
  if (!gross.ok) {
    fieldError.value = gross.message;
    await focusProblem();
    return;
  }
  fieldError.value = null;
  conflict.value = null;
  changeError.value = null;
  saving.value = true;
  const session = dialogSession;
  const name = variantName(item);
  const result = await changePrice(item.variantId, gross.grosze, item.price?.version ?? null);
  if (session !== dialogSession) {
    // The list has taken the outcome (a saved price, a refresh after a conflict); this dialog is not the one that asked.
    return;
  }
  saving.value = false;
  switch (result.status) {
    case "saved":
      closeChange();
      toast.success(`Cena zapisana: ${name}`);
      return;
    case "invalid":
    case "locked":
      fieldError.value = result.message;
      break;
    case "conflict":
      conflict.value = result.message;
      break;
    case "failed":
      changeError.value = result.message;
      break;
  }
  await focusProblem();
}

// After a failed save the focus goes to the message that says why: the field of the price for a rejected amount, the alert otherwise.
async function focusProblem(): Promise<void> {
  await nextTick();
  const target = fieldError.value !== null ? grossInput.value : conflict.value !== null ? conflictAlert.value : errorAlert.value;
  (target?.$el as HTMLElement | undefined)?.focus();
}

// The history dialog is open while a variant is chosen. Its name is kept from the moment it was opened, since the list
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

function openHistory(row: PriceRow, trigger: HTMLElement): void {
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
  focusAfterAction(trigger, (closeHistoryButton.value?.$el as HTMLElement | undefined) ?? null);
}

type HistoryRow = PriceHistoryItem & { key: string };
const historyRows = computed<HistoryRow[]>(() => history.items.value.map((entry, index) => ({ ...entry, key: String(index) })));
const historyColumns: ListColumn<HistoryRow>[] = [
  { key: "changedAt", header: "Data zmiany", main: true },
  { key: "gross", header: "Brutto", align: "right" },
  { key: "net", header: "Netto", align: "right", hideBelow: "lg", text: (row) => money(row.net, row.currency) },
  { key: "vat", header: "VAT", align: "right", hideBelow: "lg", text: (row) => money(row.vat, row.currency) },
  { key: "rate", header: "Stawka", hideBelow: "lg", text: (row) => formatRate(row.vatRate) },
  { key: "taxClass", header: "Klasa", hideBelow: "lg", text: (row) => row.taxClass },
  { key: "source", header: "Źródło" },
  { key: "actor", header: "Pracownik", hideBelow: "lg", text: (row) => row.actorName ?? "—" },
];

const DIALOG_CLASS = "max-h-[calc(100dvh-2rem)] max-w-none overflow-y-auto sm:max-w-none";
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
        @update:per-page="list.setPerPage"
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
          @previous="list.goPrevious"
          @next="list.goNext"
        />
      </div>
    </Card>

    <Dialog v-model:open="dialogOpen">
      <DialogContent
        data-testid="change-dialog"
        :class="`${DIALOG_CLASS} w-[min(28rem,calc(100vw-2rem))]`"
        @open-auto-focus="focusGross"
        @close-auto-focus="returnChangeFocus"
      >
        <DialogHeader>
          <DialogTitle>Zmień cenę</DialogTitle>
          <DialogDescription class="font-semibold text-foreground">
            {{ editing ? variantName(editing) : editingName }}
          </DialogDescription>
        </DialogHeader>
        <p
          data-testid="current-gross"
          class="text-sm"
        >
          Obecna cena brutto: {{ editing?.price ? money(editing.price.gross, editing.price.currency) : "Brak ceny" }}
        </p>
        <p
          v-if="editing"
          data-testid="rate-preview"
          class="text-sm text-muted-foreground"
        >
          Stawka VAT: {{ formatRate(editing.applicableVatRate) }}. Netto i VAT wyliczy API.
        </p>
        <Alert
          v-if="conflict"
          ref="conflictAlert"
          data-testid="change-conflict"
          tabindex="-1"
        >
          <AlertDescription>{{ conflict }}</AlertDescription>
        </Alert>
        <Alert
          v-if="changeError"
          ref="errorAlert"
          data-testid="change-error"
          variant="destructive"
          tabindex="-1"
        >
          <AlertDescription>{{ changeError }}</AlertDescription>
        </Alert>
        <FormField
          control-id="gross-input"
          label="Nowa cena brutto (zł)"
          :errors="fieldError ? [fieldError] : []"
          error-testid="gross-error"
        >
          <template #default="{ describedBy, invalid }">
            <Input
              id="gross-input"
              ref="grossInput"
              v-model="grossText"
              data-testid="gross-input"
              inputmode="decimal"
              autocomplete="off"
              placeholder="np. 54,90"
              :aria-invalid="invalid"
              :aria-describedby="describedBy"
              @keydown.enter.prevent="saveChange()"
            />
          </template>
        </FormField>
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            data-testid="cancel-change"
            @click="dialogOpen = false"
          >
            Anuluj
          </Button>
          <Button
            type="button"
            variant="interface"
            data-testid="save-price"
            :disabled="editing === null || saving"
            @click="saveChange()"
          >
            Zapisz cenę
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
          <DialogTitle>Historia ceny</DialogTitle>
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
          label="Historia ceny"
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
          <template #cell-changedAt="{ row }">
            <span data-testid="history-changed-at">{{ formatDateTime(row.changedAt) }}</span>
          </template>
          <template #cell-gross="{ row }">
            <span
              data-testid="history-gross"
              class="font-bold whitespace-nowrap text-heading"
            >{{ money(row.gross, row.currency) }}</span>
          </template>
          <template #cell-net="{ row }">
            <span
              data-testid="history-net"
              class="whitespace-nowrap"
            >{{ money(row.net, row.currency) }}</span>
          </template>
          <template #cell-vat="{ row }">
            <span
              data-testid="history-vat"
              class="whitespace-nowrap"
            >{{ money(row.vat, row.currency) }}</span>
          </template>
          <template #cell-rate="{ row }">
            <span data-testid="history-rate">{{ formatRate(row.vatRate) }}</span>
          </template>
          <template #cell-taxClass="{ row }">
            <span data-testid="history-tax-class">{{ row.taxClass }}</span>
          </template>
          <template #cell-source="{ row }">
            <span data-testid="history-source">{{ sourceLabel(row.source) }}</span>
          </template>
          <template #cell-actor="{ row }">
            <span data-testid="history-actor">{{ row.actorName ?? "—" }}</span>
          </template>
          <template #empty>
            <p
              data-testid="history-empty"
              class="px-4 py-8 text-center text-sm text-muted-foreground"
            >
              Produkt nie ma jeszcze ceny.
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
