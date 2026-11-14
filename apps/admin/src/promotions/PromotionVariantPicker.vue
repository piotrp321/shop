<script setup lang="ts">
import { Check, ChevronLeft, ChevronRight, Plus, PlusCircle, Search } from "lucide-vue-next";
import { computed, ref, useId } from "vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { useCursorPager } from "@/composables/useCursorPager";
import { MAX_NAME_SEARCH_LENGTH, useNameSearch } from "@/composables/useNameSearch";
import { formatMoney } from "@/prices/format";
import { fetchPricePage, type PriceQuery, type PriceRow, priceRows } from "@/prices/pricePage";
import { CURRENCY } from "./format";
import type { PromotionVariant } from "./usePromotionForm";

/**
 * Chooses variants from the price list (`GET /api/v1/admin/prices`): a search by name, a row per variant with its
 * catalog price, and the buttons to add one variant or all the variants of a product. A variant without a price is not
 * offered (the API refuses it in a promotion). What is added goes up as `add`; the parent decides what it does with it.
 * The list is the pager over `fetchPricePage` with a query in memory: 25 products per page, no page size to choose and
 * nothing in the address (the address belongs to the form of the promotion). The buttons are named with the variant
 * („Dodaj: Hantle regulowane Lumora Para 10 / 10 kg”), and after an add the focus goes back to the search field, because
 * the button that had it is off by then (#304 F4, F5); `focusSearch` is for the parent, which puts it there on opening.
 */
const props = defineProps<{ chosen: string[] }>();
const emit = defineEmits<{ add: [variants: PromotionVariant[]]; close: [] }>();

const PICKER_PAGE_SIZE = 25;

const query = ref<PriceQuery>({ q: null, category: null, perPage: PICKER_PAGE_SIZE });
const name = useNameSearch({ apply: (term) => (query.value = { ...query.value, q: term }) });
const pager = useCursorPager(fetchPricePage, query);

const search = name.search;
const searchHint = name.hint;
const rows = computed(() => priceRows(pager.items.value));
const { loading, loaded, error, hasPrevious, hasNext, pageNumber, goNext, goPrevious, retry } = pager;

const hintId = useId();
const searchField = ref<InstanceType<typeof Input> | null>(null);

function focusSearch(): void {
  (searchField.value?.$el as HTMLElement | undefined)?.focus();
}
defineExpose({ focusSearch });

// The name of a variant in the names of the buttons: the product, and the label unless it is the default variant.
const variantName = (row: PriceRow) => (row.label === "" ? row.name : `${row.name} / ${row.label}`);

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
  focusSearch();
}

/** The variants of a product that "Dodaj cały produkt" would add: those with a price that are not chosen yet. */
function addableOf(productId: string): PriceRow[] {
  return rows.value.filter((row) => row.productId === productId && row.price !== null && !isChosen(row));
}

function addProduct(productId: string): void {
  emit("add", addableOf(productId).map(toVariant));
  focusSearch();
}

function isChosen(row: PriceRow): boolean {
  return props.chosen.includes(row.variantId);
}

// The text of an error is the colour of the status „danger” (#446): the contrast of the default one is lower on the card.
const ERROR_ALERT = "border-status-danger text-status-danger *:data-[slot=alert-description]:text-status-danger";
</script>

<template>
  <section
    data-testid="picker"
    class="flex flex-col gap-3 rounded-lg border border-separator p-4"
    aria-label="Wybór wariantów"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex w-full max-w-sm min-w-0 flex-col gap-1 sm:w-72">
        <div class="relative">
          <Search
            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <Input
            ref="searchField"
            v-model="search"
            data-testid="picker-search"
            type="search"
            class="pl-9"
            :maxlength="MAX_NAME_SEARCH_LENGTH"
            placeholder="Szukaj produktu po nazwie"
            aria-label="Szukaj produktu po nazwie"
            :aria-describedby="hintId"
          />
        </div>
        <p
          :id="hintId"
          role="status"
          data-testid="picker-hint"
          class="text-xs text-muted-foreground empty:hidden"
        >
          {{ searchHint }}
        </p>
      </div>
      <Button
        type="button"
        variant="outline"
        data-testid="close-picker"
        @click="emit('close')"
      >
        Zamknij wybór
      </Button>
    </div>

    <Alert
      v-if="error"
      data-testid="picker-error"
      variant="destructive"
      :class="ERROR_ALERT"
    >
      <AlertDescription class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span>{{ error }}</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          data-testid="picker-retry"
          :disabled="loading"
          @click="retry()"
        >
          Spróbuj ponownie
        </Button>
      </AlertDescription>
    </Alert>

    <template v-if="loaded">
      <p
        v-if="rows.length === 0"
        data-testid="picker-empty"
        class="py-4 text-center text-sm text-muted-foreground"
      >
        Brak produktów spełniających kryteria.
      </p>
      <div
        v-else
        role="region"
        aria-label="Warianty do wyboru"
        tabindex="0"
        :aria-busy="loading"
        class="overflow-x-auto"
      >
        <table class="w-full caption-bottom text-sm">
          <TableHeader>
            <TableRow class="bg-muted hover:bg-muted">
              <TableHead
                scope="col"
                class="px-2.5 text-xs font-bold whitespace-normal text-heading uppercase"
              >
                Produkt
              </TableHead>
              <TableHead
                scope="col"
                class="px-2.5 text-xs font-bold whitespace-normal text-heading uppercase"
              >
                Wariant
              </TableHead>
              <TableHead
                scope="col"
                class="px-2.5 text-right text-xs font-bold whitespace-normal text-heading uppercase"
              >
                Cena katalogowa
              </TableHead>
              <TableHead
                scope="col"
                class="px-2.5 text-xs font-bold whitespace-normal text-heading uppercase"
              >
                Dodaj
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            <TableRow
              v-for="row in rows"
              :key="row.variantId"
              data-testid="picker-row"
            >
              <TableCell class="px-2.5 py-3 whitespace-normal">
                <span
                  v-if="row.first"
                  class="font-bold text-heading"
                >{{ row.name }}</span>
              </TableCell>
              <TableCell class="px-2.5 py-3 whitespace-normal">
                {{ row.label === "" ? "Wariant domyślny" : row.label }}
              </TableCell>
              <TableCell class="px-2.5 py-3 text-right tabular-nums">
                {{ row.price ? formatMoney(row.price.gross, CURRENCY) : "Brak ceny" }}
              </TableCell>
              <TableCell class="px-2.5 py-3">
                <div class="flex flex-wrap gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-testid="add-variant"
                    :aria-label="`${isChosen(row) ? 'Dodano' : 'Dodaj'}: ${variantName(row)}`"
                    :disabled="isChosen(row) || row.price === null"
                    @click="addVariant(row)"
                  >
                    <Check
                      v-if="isChosen(row)"
                      aria-hidden="true"
                    />
                    <Plus
                      v-else
                      aria-hidden="true"
                    />
                    {{ isChosen(row) ? "Dodano" : "Dodaj" }}
                  </Button>
                  <Button
                    v-if="row.first"
                    type="button"
                    variant="outline"
                    size="sm"
                    data-testid="add-product"
                    :aria-label="`Dodaj cały produkt: ${row.name}`"
                    :disabled="addableOf(row.productId).length === 0"
                    @click="addProduct(row.productId)"
                  >
                    <PlusCircle aria-hidden="true" />
                    Dodaj cały produkt
                  </Button>
                </div>
              </TableCell>
            </TableRow>
          </TableBody>
        </table>
      </div>
    </template>
    <div
      v-else-if="loading && !error"
      class="flex flex-col gap-2"
    >
      <Skeleton
        v-for="index in 3"
        :key="index"
        class="h-12"
      />
    </div>

    <div
      v-if="loaded"
      class="flex flex-wrap items-center justify-end gap-3"
    >
      <Button
        type="button"
        variant="outline"
        size="sm"
        data-testid="picker-previous"
        :disabled="!hasPrevious || loading"
        @click="goPrevious()"
      >
        <ChevronLeft aria-hidden="true" />
        Poprzednia
      </Button>
      <span class="min-w-20 text-center text-sm tabular-nums">Strona {{ pageNumber }}</span>
      <Button
        type="button"
        variant="outline"
        size="sm"
        data-testid="picker-next"
        :disabled="!hasNext || loading"
        @click="goNext()"
      >
        Następna
        <ChevronRight aria-hidden="true" />
      </Button>
    </div>
  </section>
</template>
