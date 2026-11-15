<script setup lang="ts">
import { computed, nextTick, ref } from "vue";
import { toast } from "vue-sonner";
import FormField from "@/components/FormField.vue";
import DataTable, { type ListColumn } from "@/components/list/DataTable.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { focusAfterAction } from "@/lib/focus";
import { variantName } from "@/lib/variantName";
import { formatDateTime, formatMoney, formatRate, sourceLabel } from "@/prices/format";
import { parseGross } from "@/prices/parseGross";
import type { PriceChange } from "@/prices/priceChange";
import type { PriceRow } from "@/prices/pricePage";
import type { PriceHistoryItem } from "@/prices/usePriceHistory";
import { usePriceHistory } from "@/prices/usePriceHistory";

/**
 * The windows of a price: „Zmień cenę” and „Historia ceny”, shared by the price list and the card of a product. The
 * window follows its row by variant id in `rows`, so after a conflict reloads the rows it shows the current price and
 * version. `changePrice` is the request of the view (it keeps the saved price and reloads after a conflict) and
 * `fallbackFocus` gives the element that takes the focus when the button that opened a window is gone.
 */
export type PriceDialogRow = Pick<PriceRow, "variantId" | "name" | "label" | "price" | "applicableVatRate">;

const props = defineProps<{
  rows: PriceDialogRow[];
  changePrice: (variantId: string, gross: number, version: number | null) => Promise<PriceChange>;
  fallbackFocus: () => HTMLElement | null;
}>();

const history = usePriceHistory();

const money = (amount: number, currency: string) => formatMoney(amount, currency);

// The dialog follows the row by variant id, so after a conflict refreshes the page it shows the current price and version.
const editingId = ref<string | null>(null);
const editingName = ref("");
const editing = computed(() => props.rows.find((row) => row.variantId === editingId.value) ?? null);
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

function openChange(row: PriceDialogRow, trigger: HTMLElement): void {
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
  focusAfterAction(changeOpener, props.fallbackFocus());
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
  const result = await props.changePrice(item.variantId, gross.grosze, item.price?.version ?? null);
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

function openHistory(row: PriceDialogRow, trigger: HTMLElement): void {
  historyName.value = variantName(row);
  historyOpener = trigger;
  void history.open(row.variantId);
}

function returnHistoryFocus(event: Event): void {
  event.preventDefault();
  focusAfterAction(historyOpener, props.fallbackFocus());
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

defineExpose({ openChange, openHistory });
</script>

<template>
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
</template>
