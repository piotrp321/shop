<script setup lang="ts">
/**
 * The shadows at the edges of a scrolling region that has more behind them (`useScrollEdges`). They are placed in the
 * element that wraps the region (`relative`) and do not take the pointer.
 */
defineProps<{ edges: { start: boolean; end: boolean } }>();
</script>

<template>
  <div
    v-show="edges.start"
    aria-hidden="true"
    data-testid="scroll-edge-start"
    class="pointer-events-none absolute inset-y-0 left-0 w-px shadow-md"
  />
  <div
    v-show="edges.end"
    aria-hidden="true"
    data-testid="scroll-edge-end"
    class="pointer-events-none absolute inset-y-0 right-0 w-px shadow-md"
  />
</template>
