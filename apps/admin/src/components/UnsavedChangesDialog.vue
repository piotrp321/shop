<script setup lang="ts">
import ConfirmDialog from "@/components/ConfirmDialog.vue";

/**
 * The question of `useUnsavedChangesGuard` (docs/features/admin-design/spec.md, „Standard formularza i okien”): „Zostań”
 * has the focus, „Opuść bez zapisu” is red. Closing it (Escape, „Zostań”) only closes; the guard takes that as staying.
 */
const open = defineModel<boolean>("open", { default: false });
defineProps<{
  /** Where the focus goes when nothing had it as the question opened (e.g. the Back button): the H1 of the form. */
  fallbackFocus?: HTMLElement | null;
}>();
defineEmits<{ leave: [] }>();
</script>

<template>
  <ConfirmDialog
    v-model:open="open"
    title="Masz niezapisane zmiany"
    description="Jeśli opuścisz tę stronę bez zapisu, zmiany zostaną utracone."
    confirm-label="Opuść bez zapisu"
    cancel-label="Zostań"
    destructive
    :fallback-focus="fallbackFocus"
    @confirm="$emit('leave')"
  />
</template>
