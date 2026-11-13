<script setup lang="ts">
import { ref, useId } from "vue";

/**
 * A card of a form with a title (docs/features/admin-design/spec.md, „Standard formularza i okien”). The heading is the
 * target of the focus when an action removed the button it was started with („Status”, „Zdjęcia”, „Warianty”), so it
 * has `tabindex="-1"`; `heading` is the element, for `ConfirmDialog` (`fallback-focus`), and `focusHeading()` moves the focus.
 */
defineProps<{ title: string }>();

const headingId = useId();
const heading = ref<HTMLElement | null>(null);

defineExpose({ heading, focusHeading: () => heading.value?.focus() });
</script>

<template>
  <section
    :aria-labelledby="headingId"
    class="flex flex-col gap-4 rounded-lg border border-separator bg-card p-5 text-card-foreground shadow-sm"
  >
    <h2
      :id="headingId"
      ref="heading"
      tabindex="-1"
      class="text-base font-bold text-heading focus:outline-none"
      v-text="title"
    />
    <slot />
  </section>
</template>
