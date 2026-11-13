<script setup lang="ts">
import { Button } from "@/components/ui/button";

/**
 * The empty list (docs/features/admin-design/spec.md, „Standard listy”): without any data (`emptyText` of the list, with
 * the main action of the list in the `action` slot, which the view fills only for a role that can create) or without
 * results for the filters (the same text for every list, with „Wyczyść filtry”).
 */
defineProps<{ filtered: boolean; emptyText: string }>();

defineEmits<{ clear: [] }>();
</script>

<template>
  <div
    data-testid="empty"
    class="flex flex-col items-center gap-4 px-4 py-10 text-center"
  >
    <template v-if="filtered">
      <p class="text-sm text-muted-foreground">
        Brak wyników dla wybranych filtrów.
      </p>
      <Button
        type="button"
        variant="outline"
        data-testid="clear-filters"
        @click="$emit('clear')"
      >
        Wyczyść filtry
      </Button>
    </template>
    <template v-else>
      <p class="text-sm text-muted-foreground">
        {{ emptyText }}
      </p>
      <slot name="action" />
    </template>
  </div>
</template>
