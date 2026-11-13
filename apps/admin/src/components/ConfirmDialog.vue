<script setup lang="ts">
import { watch } from "vue";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { buttonVariants } from "@/components/ui/button";
import { focusAfterAction } from "@/lib/focus";

/**
 * The confirmation of an irreversible action or one that changes a state (docs/features/admin-design/spec.md,
 * „Standard formularza i okien”). The title names the object („Anulować zamówienie R-100002?”), the description says the
 * consequence, the button says the effect (red when `destructive`), and „Wróć” has the focus when the dialog opens.
 * Closing (Escape, „Wróć”, the action) gives the focus back to the element that had it when the dialog opened; when that
 * element is gone by then, to `fallbackFocus`, the heading of the card the action belongs to (`tabindex="-1"`).
 */
const props = withDefaults(
  defineProps<{
    title: string;
    description: string;
    /** The effect of the action as a label: „Anuluj zamówienie”, „Usuń promocję”. */
    confirmLabel: string;
    destructive?: boolean;
    cancelLabel?: string;
    fallbackFocus?: HTMLElement | null;
  }>(),
  { destructive: false, cancelLabel: "Wróć", fallbackFocus: null },
);

const open = defineModel<boolean>("open", { default: false });
const emit = defineEmits<{ confirm: [] }>();

// The element to give the focus back to, taken at the moment the dialog opens, before it moves the focus inside.
let opener: HTMLElement | null = null;
watch(
  open,
  (isOpen) => {
    if (isOpen) {
      opener = document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : null;
    }
  },
  { flush: "sync" },
);

function returnFocus(event: Event) {
  event.preventDefault();
  focusAfterAction(opener, props.fallbackFocus);
  opener = null;
}
</script>

<template>
  <AlertDialog v-model:open="open">
    <AlertDialogContent @close-auto-focus="returnFocus">
      <AlertDialogHeader>
        <AlertDialogTitle>{{ title }}</AlertDialogTitle>
        <AlertDialogDescription>{{ description }}</AlertDialogDescription>
      </AlertDialogHeader>
      <AlertDialogFooter>
        <AlertDialogCancel>{{ cancelLabel }}</AlertDialogCancel>
        <AlertDialogAction
          :class="buttonVariants({ variant: destructive ? 'destructive' : 'interface' })"
          @click="emit('confirm')"
        >
          {{ confirmLabel }}
        </AlertDialogAction>
      </AlertDialogFooter>
    </AlertDialogContent>
  </AlertDialog>
</template>
