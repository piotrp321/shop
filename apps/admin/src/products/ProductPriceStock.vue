<script setup lang="ts">
import { ClipboardPen, History, PackagePlus, Pencil, ScrollText } from "lucide-vue-next";
import { computed, onMounted, ref, watch } from "vue";
import FormSection from "@/components/FormSection.vue";
import DataTable, { type ListColumn } from "@/components/list/DataTable.vue";
import RowActions, { type RowAction } from "@/components/list/RowActions.vue";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { levelLabel } from "@/inventory/format";
import StockDialogs from "@/inventory/StockDialogs.vue";
import { variantName } from "@/lib/variantName";
import { formatMoney } from "@/prices/format";
import PriceDialogs from "@/prices/PriceDialogs.vue";
import { useAuthStore } from "@/stores/auth";
import { type PriceStockRow, useProductPriceStock } from "./useProductPriceStock";

/**
 * The card „Ceny i stany” of the form of a saved product (docs/features/admin-features/spec.md, section 2): a row per
 * variant saved in the API, with its price and stock and the actions of the price and stock lists, which use the same
 * windows. Saving in a window changes nothing in the form of the product. `reloadKey` is the version of the saved
 * product: when it changes (the product was saved) the card reads the prices and stocks again. `hasUnsavedVariants`
 * tells that the variant editor has a row the API does not know yet, which has no price and stock to set.
 */
const props = defineProps<{ productId: string; reloadKey: number; hasUnsavedVariants: boolean }>();

const { rows, loading, loaded, error, forbidden, load, changePrice, receiveStock, correctStock } = useProductPriceStock(() => props.productId);

onMounted(load);
watch(
  () => [props.productId, props.reloadKey],
  () => void load(),
);

// Only hides the actions: the API checks the permission on every change. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canChangePrices = computed(() => auth.staff?.roles.includes("admin") ?? false);
const canChangeStock = computed(() => auth.staff?.roles.some((role) => role === "admin" || role === "warehouse") ?? false);

const section = ref<InstanceType<typeof FormSection> | null>(null);
const priceDialogs = ref<InstanceType<typeof PriceDialogs> | null>(null);
const stockDialogs = ref<InstanceType<typeof StockDialogs> | null>(null);

const hasUnpriced = computed(() => rows.value.some((row) => row.price === null));

const money = (amount: number, currency: string) => formatMoney(amount, currency);
// A variant without a stock record has no numbers: the card shows a dash and does not make up a level for it.
const number = (value: number | undefined) => (value === undefined ? "—" : String(value));

const columns: ListColumn<PriceStockRow>[] = [
  { key: "variant", header: "Wariant", main: true },
  { key: "gross", header: "Brutto", align: "right" },
  { key: "net", header: "Netto", align: "right", hideBelow: "lg", text: (row) => (row.price ? money(row.price.net, row.price.currency) : "—") },
  { key: "vat", header: "VAT", align: "right", hideBelow: "lg", text: (row) => (row.price ? money(row.price.vat, row.price.currency) : "—") },
  { key: "physical", header: "Fizycznie", align: "right" },
  { key: "reserved", header: "Zarezerwowane", align: "right", hideBelow: "lg", text: (row) => number(row.stock?.reserved) },
  { key: "available", header: "Dostępne", align: "right" },
  { key: "level", header: "Poziom" },
  { key: "actions", header: "Akcje", align: "right" },
];

const rowActions = computed<RowAction[]>(() => [
  ...(canChangePrices.value ? [{ key: "change-price", label: "Zmień cenę", icon: Pencil, testid: "change-price" }] : []),
  ...(canChangeStock.value
    ? [
        { key: "receive", label: "Przyjęcie", icon: PackagePlus, testid: "receive-stock" },
        { key: "correct", label: "Korekta", icon: ClipboardPen, testid: "correct-stock" },
      ]
    : []),
  { key: "price-history", label: "Historia ceny", icon: History, testid: "show-price-history" },
  { key: "stock-history", label: "Historia stanu", icon: ScrollText, testid: "show-stock-history" },
]);

function onAction(key: string, row: PriceStockRow, trigger: HTMLElement): void {
  switch (key) {
    case "change-price":
      priceDialogs.value?.openChange(row, trigger);
      break;
    case "price-history":
      priceDialogs.value?.openHistory(row, trigger);
      break;
    case "receive":
      stockDialogs.value?.openReceipt(row, trigger);
      break;
    case "correct":
      stockDialogs.value?.openCorrection(row, trigger);
      break;
    default:
      stockDialogs.value?.openHistory(row, trigger);
  }
}

const fallbackFocus = () => section.value?.heading ?? null;
</script>

<template>
  <FormSection
    ref="section"
    title="Ceny i stany"
  >
    <Alert
      v-if="hasUnpriced"
      variant="warning"
      data-testid="no-price-warning"
    >
      <p class="col-start-2">
        Warianty bez ceny są w sklepie niedostępne.
      </p>
    </Alert>
    <p
      v-if="hasUnsavedVariants"
      data-testid="unsaved-variants-note"
      class="text-sm text-muted-foreground"
    >
      Zapisz produkt, żeby ustawić cenę i stan.
    </p>
    <DataTable
      label="Ceny i stany wariantów"
      :columns="columns"
      :rows="rows"
      :row-key="(row: PriceStockRow) => row.variantId"
      row-testid="price-stock-row"
      error-testid="price-stock-error"
      :loading="loading"
      :loaded="loaded"
      :error="error"
      :forbidden="forbidden"
      :retry="load"
    >
      <template #cell-variant="{ row }">
        <span
          data-testid="variant-label"
          class="break-words"
        >{{ row.label === "" ? "Wariant domyślny" : row.label }}</span>
      </template>
      <template #cell-gross="{ row }">
        <Badge
          v-if="row.price === null"
          data-testid="no-price"
          variant="warning"
        >
          Bez ceny
        </Badge>
        <span
          v-else
          data-testid="gross"
          class="font-bold whitespace-nowrap text-heading"
        >{{ money(row.price.gross, row.price.currency) }}</span>
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
        <p
          data-testid="price-stock-empty"
          class="px-4 py-8 text-center text-sm text-muted-foreground"
        >
          Produkt nie ma jeszcze zapisanych wariantów.
        </p>
      </template>
    </DataTable>

    <PriceDialogs
      ref="priceDialogs"
      :rows="rows"
      :change-price="changePrice"
      :fallback-focus="fallbackFocus"
    />
    <StockDialogs
      ref="stockDialogs"
      :rows="rows"
      :receive-stock="receiveStock"
      :correct-stock="correctStock"
      :fallback-focus="fallbackFocus"
    />
  </FormSection>
</template>
