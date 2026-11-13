<script setup lang="ts">
import { ChevronLeft, ChevronRight } from "lucide-vue-next";
import { computed, ref, watch } from "vue";
import { Button } from "@/components/ui/button";

/**
 * The footer of a list (docs/features/admin-design/spec.md, „Standard listy”): „Pokazano X–Y” in a status region and the
 * buttons „Poprzednia strona” and „Następna strona” with the number of the page. The API has no total, so there is no
 * „z N” and no numbers of further pages. The focus stays on the pressed button, and when that button is disabled by the
 * page that came (the last or the first one) it moves to the other one, never to the body.
 */
const props = withDefaults(
  defineProps<{
    hasPrevious: boolean;
    hasNext: boolean;
    pageNumber: number;
    /** The numbers of the first and the last row of the page, from `useCursorPager`; null when there are no rows. */
    range: { from: number; to: number } | null;
    /** What the numbers count when it is not the rows of the list: „produkty” gives „Pokazano produkty 1–25”. */
    noun?: string;
  }>(),
  { noun: "" },
);

const rangeText = computed(() => (props.range === null ? "" : `Pokazano ${props.noun ? `${props.noun} ` : ""}${props.range.from}–${props.range.to}`));

const emit = defineEmits<{ previous: []; next: [] }>();

const previousButton = ref<InstanceType<typeof Button> | null>(null);
const nextButton = ref<InstanceType<typeof Button> | null>(null);
// The button the staff member pressed last, while the focus is in the pager.
let pressed: "previous" | "next" | null = null;

function press(which: "previous" | "next") {
  pressed = which;
  if (which === "next") {
    emit("next");
  } else {
    emit("previous");
  }
}

// The focus moved to another element of the page: the staff member is no longer in the pager. A focus that is lost
// without a target is not that: it is what a browser may do as the pressed button is disabled.
function onFocusOut(event: FocusEvent) {
  const nav = event.currentTarget as HTMLElement;
  if (event.relatedTarget instanceof Node && !nav.contains(event.relatedTarget)) {
    pressed = null;
  }
}

// Runs after the DOM is patched, when the pressed button is disabled already.
watch(
  () => [props.hasPrevious, props.hasNext],
  () => {
    if (pressed === "next" && !props.hasNext && props.hasPrevious) {
      (previousButton.value?.$el as HTMLElement | undefined)?.focus();
    } else if (pressed === "previous" && !props.hasPrevious && props.hasNext) {
      (nextButton.value?.$el as HTMLElement | undefined)?.focus();
    }
  },
  { flush: "post" },
);
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
