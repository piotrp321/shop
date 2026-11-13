<script setup lang="ts">
import { cn } from "@/lib/utils";

/** The mark of Worf: an orange diamond and the word. `inverted` is for the graphite sidebar (white word). */
defineProps<{ inverted?: boolean }>();
</script>

<template>
  <span class="inline-flex items-center gap-3">
    <span
      class="size-2.5 rotate-45 bg-primary"
      aria-hidden="true"
    />
    <span :class="cn('text-3xl leading-none font-extrabold tracking-tight', inverted ? 'text-sidebar-active' : 'text-heading')">Worf</span>
  </span>
</template>
