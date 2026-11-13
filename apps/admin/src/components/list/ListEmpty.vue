<script setup lang="ts">
import { Button } from "@/components/ui/button";

/**
 * The empty list (docs/features/admin-design/spec.md, „Standard listy”): without any data (`emptyText` of the list, with
 * the main action of the list in the `action` slot, which the view fills only for a role that can create) or without
 * results for the filters (the same text for every list, with „Wyczyść filtry”). A page after the first that the API
 * answered with no rows (the list changed since the previous page) is neither: `laterPage` says only that the page is
 * empty, and the pager of the list stays so that the staff member can go back. The buttons have their own test ids
 * (`empty-clear-filters`, and the action of the view), as the toolbar has the same buttons.
 */
withDefaults(defineProps<{ filtered: boolean; emptyText: string; laterPage?: boolean }>(), { laterPage: false });

defineEmits<{ clear: [] }>();
</script>

<template>
  <div
    data-testid="empty"
    class="flex flex-col items-center gap-4 px-4 py-10 text-center"
  >
    <p
      v-if="laterPage"
      class="text-sm text-muted-foreground"
    >
      Na tej stronie nie ma już wyników.
    </p>
    <template v-else-if="filtered">
      <p class="text-sm text-muted-foreground">
        Brak wyników dla wybranych filtrów.
      </p>
      <Button
        type="button"
        variant="outline"
        data-testid="empty-clear-filters"
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
