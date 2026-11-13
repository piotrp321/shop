<script setup lang="ts">
import { Check, LoaderCircle } from "lucide-vue-next";
import { onBeforeUnmount, onMounted, ref } from "vue";
import { Button } from "@/components/ui/button";

/**
 * The save bar of a form (docs/features/admin-design/spec.md, „Standard formularza i okien”): stuck to the bottom of the
 * content area, white with a shadow. The form shows it (`v-if`) when it has unsaved changes, and from the start for a new
 * object (`creating`: the label of the object instead of „Niezapisane zmiany”, „Anuluj” instead of „Odrzuć”). „Odrzuć”
 * restores the state from the API without a request, „Anuluj” goes back to the list; the form handles both.
 * While it is shown, `scroll-padding-bottom` of the page equals its height, so that a field the focus moves to is never
 * under the bar (WCAG 2.4.11).
 */
withDefaults(
  defineProps<{
    creating?: boolean;
    /** What the bar says for a new object, e.g. „Nowy produkt”. */
    newLabel?: string;
    busy?: boolean;
    saveDisabled?: boolean;
  }>(),
  { creating: false, newLabel: "Nowy obiekt", busy: false, saveDisabled: false },
);
defineEmits<{ save: []; discard: []; cancel: [] }>();

const bar = ref<HTMLElement | null>(null);
let observer: ResizeObserver | undefined;

function reserveRoom(): void {
  document.documentElement.style.scrollPaddingBottom = `${bar.value?.offsetHeight ?? 0}px`;
}

onMounted(() => {
  reserveRoom();
  observer = new ResizeObserver(reserveRoom);
  if (bar.value) {
    observer.observe(bar.value);
  }
});
onBeforeUnmount(() => {
  observer?.disconnect();
  document.documentElement.style.scrollPaddingBottom = "";
});
</script>

<template>
  <section
    ref="bar"
    aria-label="Zapis formularza"
    class="sticky bottom-0 z-20 -mx-4 -mb-4 flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t border-separator bg-card px-4 py-3 shadow-lg sm:-mx-6 sm:-mb-6 sm:px-6"
  >
    <p class="text-sm font-semibold text-heading">
      {{ creating ? newLabel : "Niezapisane zmiany" }}
    </p>
    <div class="flex items-center gap-2">
      <Button
        v-if="creating"
        type="button"
        variant="outline"
        data-testid="cancel"
        @click="$emit('cancel')"
      >
        Anuluj
      </Button>
      <Button
        v-else
        type="button"
        variant="outline"
        data-testid="discard"
        :disabled="busy"
        @click="$emit('discard')"
      >
        Odrzuć
      </Button>
      <Button
        type="button"
        data-testid="save"
        :aria-busy="busy"
        :disabled="saveDisabled || busy"
        @click="$emit('save')"
      >
        <LoaderCircle
          v-if="busy"
          class="animate-spin"
          aria-hidden="true"
        />
        <Check
          v-else
          aria-hidden="true"
        />
        Zapisz
      </Button>
    </div>
  </section>
</template>
