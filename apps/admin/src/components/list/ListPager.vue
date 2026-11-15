<script setup lang="ts">
import { ChevronLeft, ChevronRight } from "lucide-vue-next";
import { computed, ref, toRef } from "vue";
import { Button } from "@/components/ui/button";
import type { ListTotal } from "@/composables/useCursorPager";
import { usePagerFocus } from "@/composables/usePagerFocus";

/**
 * The footer of a list (docs/features/admin-design/spec.md, „Standard listy”): „Pokazano X–Y” in a status region and the
 * buttons „Poprzednia strona” and „Następna strona” with the number of the page. The total of the first page of the
 * list (the API counts at most up to its limit) gives „Pokazano 1–25 z 1 234”, or „z ponad 10 000” when the count stopped
 * at the limit; without it there is no „z N”. There are no numbers of further pages. The focus stays on the pressed button, and when that button is disabled by the
 * page that came (the last or the first one) it moves to the other one, never to the body.
 */
const props = withDefaults(
  defineProps<{
    hasPrevious: boolean;
    hasNext: boolean;
    pageNumber: number;
    /** The numbers of the first and the last row of the page, from `useCursorPager`; null when there are no rows. */
    range: { from: number; to: number } | null;
    /** The total of the list from `useCursorPager`; null or omitted when it is not known. */
    total?: ListTotal | null;
    /** What the numbers count when it is not the rows of the list: „produkty” gives „Pokazano produkty 1–25”. */
    noun?: string;
  }>(),
  { noun: "", total: null },
);

// Always grouped (the Polish rule leaves four digits together), with a no-break space so that a number never splits.
const number = new Intl.NumberFormat("pl-PL", { useGrouping: "always" });

const totalText = computed(() => {
  if (props.total === null) {
    return "";
  }
  return props.total.capped ? ` z ponad ${number.format(props.total.value)}` : ` z ${number.format(props.total.value)}`;
});

const rangeText = computed(() =>
  props.range === null ? "" : `Pokazano ${props.noun ? `${props.noun} ` : ""}${props.range.from}–${props.range.to}${totalText.value}`,
);

const emit = defineEmits<{ previous: []; next: [] }>();

const previousButton = ref<InstanceType<typeof Button> | null>(null);
const nextButton = ref<InstanceType<typeof Button> | null>(null);
const { press: remember, onFocusOut } = usePagerFocus({
  hasPrevious: toRef(props, "hasPrevious"),
  hasNext: toRef(props, "hasNext"),
  previous: previousButton,
  next: nextButton,
});

function press(which: "previous" | "next") {
  remember(which);
  if (which === "next") {
    emit("next");
  } else {
    emit("previous");
  }
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
    <p
      role="status"
      data-testid="range"
      class="text-sm text-muted-foreground"
    >
      {{ rangeText }}
    </p>
    <nav
      aria-label="Stronicowanie"
      class="flex items-center gap-2"
      @focusout="onFocusOut"
    >
      <Button
        ref="previousButton"
        type="button"
        variant="outline"
        size="icon-sm"
        data-testid="previous-page"
        aria-label="Poprzednia strona"
        :disabled="!hasPrevious"
        @click="press('previous')"
      >
        <ChevronLeft aria-hidden="true" />
      </Button>
      <span
        data-testid="page-number"
        class="min-w-20 text-center text-sm text-foreground tabular-nums"
      >Strona {{ pageNumber }}</span>
      <Button
        ref="nextButton"
        type="button"
        variant="outline"
        size="icon-sm"
        data-testid="next-page"
        aria-label="Następna strona"
        :disabled="!hasNext"
        @click="press('next')"
      >
        <ChevronRight aria-hidden="true" />
      </Button>
    </nav>
  </div>
</template>
