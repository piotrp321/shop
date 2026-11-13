<script lang="ts" setup>
import type { HTMLAttributes } from "vue"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <nav
    aria-label="Ścieżka nawigacji"
    data-slot="breadcrumb"
    :class="props.class"
  >
    <slot />
  </nav>
</template>
