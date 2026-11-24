<script setup lang="ts">
import { Check, LoaderCircle } from "lucide-vue-next";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { Button } from "@/components/ui/button";

/**
 * The save bar of a form (docs/features/admin-design/spec.md, „Standard formularza i okien”): stuck to the bottom of the
 * content area, white with a shadow. The form shows it (`v-if`) when it has unsaved changes, and from the start for a new
 * object (`creating`: the label of the object instead of „Niezapisane zmiany”, „Anuluj” instead of „Odrzuć”). „Odrzuć”
 * restores the state from the API without a request, „Anuluj” goes back to the list; the form handles both.
 * „Zapisz” is off while a request runs (`busy`) or saving is not possible (`saveDisabled`) through `aria-disabled`, not
 * `disabled`: the button the staff member pressed keeps the focus until the answer (a disabled one would drop it on `body`),
 * and a press while it is off emits nothing.
 * While it is shown, `scroll-padding-bottom` of the page equals its height, so that a field the focus moves to is never
 * under the bar (WCAG 2.4.11).
 */
const props = withDefaults(
  defineProps<{
    creating?: boolean;
    /** What the bar says for a new object, e.g. „Nowy produkt”. */
    newLabel?: string;
    busy?: boolean;
    saveDisabled?: boolean;
    /** The id of the element that says why „Zapisz” is off (e.g. „Promocja kończy się”); it describes the button. */
    saveDescribedBy?: string;
  }>(),
  { creating: false, newLabel: "Nowy obiekt", busy: false, saveDisabled: false, saveDescribedBy: undefined },
);
const emit = defineEmits<{ save: []; discard: []; cancel: [] }>();

const saveOff = computed(() => props.saveDisabled || props.busy);

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
        :disabled="busy || saveDisabled"
        @click="$emit('discard')"
      >
        Odrzuć
      </Button>
      <Button
        type="button"
        data-testid="save"
        :aria-busy="busy"
        :aria-describedby="saveDescribedBy"
        class="aria-disabled:opacity-50"
        :aria-disabled="saveOff ? 'true' : undefined"
        @click="!saveOff && emit('save')"
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
