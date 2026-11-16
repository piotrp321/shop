<script setup lang="ts">
import { nextTick, ref, watch } from "vue";
import CategoryCombobox, { ROOT_CATEGORY } from "@/components/CategoryCombobox.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import type { CategoryTreeNode } from "@/composables/useCategoryTree";
import { focusAfterAction } from "@/lib/focus";
import { ROOT_PLACE } from "./tree";
import type { CategoryOutcome } from "./useCategoryScreen";

/**
 * The window of „Przenieś do…”: the picker of the new parent with the top level as the first option and the category
 * with its subtree disabled (the one thing the panel rules out itself, as it can never be right). The category goes last
 * among the children of the chosen parent; the API decides on everything else (the depth, the slug among the new
 * siblings), and its refusal is said in the alert of the window with the focus on it. A move closes the window and tells
 * the view (`saved`).
 */
const props = defineProps<{
  /** The name of the category, kept from the moment the window opened. */
  name: string;
  /** Where the category is now, in words („Turystyka › Biwak”, „Poziom główny”). */
  place: string;
  nodes: CategoryTreeNode[];
  /** The category itself and everything below it. */
  disabledIds: string[];
  submit: (parentId: string | null) => Promise<CategoryOutcome>;
  returnFocusTo: HTMLElement | null;
  fallbackFocus: HTMLElement | null;
}>();
const emit = defineEmits<{ saved: [outcome: Extract<CategoryOutcome, { status: "saved" }>] }>();

const open = defineModel<boolean>("open", { required: true });

const chosen = ref<string | null>(null);
const message = ref<string | null>(null);
const saving = ref(false);
const alert = ref<InstanceType<typeof Alert> | null>(null);
let session = 0;

watch(
  open,
  () => {
    session++;
    chosen.value = null;
    message.value = null;
    saving.value = false;
  },
  { immediate: true },
);

function choose(value: string | string[] | null) {
  chosen.value = typeof value === "string" ? value : null;
}

function returnFocus(event: Event) {
  event.preventDefault();
  focusAfterAction(props.returnFocusTo, props.fallbackFocus);
}

async function save() {
  if (saving.value || chosen.value === null) {
    return;
  }
  message.value = null;
  saving.value = true;
  const current = session;
  const outcome = await props.submit(chosen.value === ROOT_CATEGORY ? null : chosen.value);
  if (current !== session) {
    return;
  }
  saving.value = false;
  if (outcome.status === "saved") {
    open.value = false;
    emit("saved", outcome);
    return;
  }
  message.value = outcome.problem.message;
  await nextTick();
  (alert.value?.$el as HTMLElement | undefined)?.focus();
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent
      data-testid="move-dialog"
      class="max-h-[calc(100dvh-2rem)] w-[min(28rem,calc(100vw-2rem))] max-w-none overflow-y-auto sm:max-w-none"
      @close-auto-focus="returnFocus"
    >
      <DialogHeader>
        <DialogTitle>Przenieś kategorię {{ name }}</DialogTitle>
        <DialogDescription>
          Obecnie: {{ place }}. Kategoria trafi na koniec wybranego miejsca razem z podkategoriami.
        </DialogDescription>
      </DialogHeader>
      <form
        class="flex flex-col gap-4"
        @submit.prevent="save"
      >
        <Alert
          v-if="message"
          ref="alert"
          data-testid="dialog-error"
          variant="destructive"
          tabindex="-1"
        >
          <AlertDescription>{{ message }}</AlertDescription>
        </Alert>
        <CategoryCombobox
          label="Nowa kategoria nadrzędna"
          placeholder="Wybierz miejsce"
          :nodes="nodes"
          :model-value="chosen"
          :root-label="ROOT_PLACE"
          :disabled-ids="disabledIds"
          @update:model-value="choose"
        />
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            data-testid="cancel-move"
            @click="open = false"
          >
            Anuluj
          </Button>
          <Button
            type="submit"
            variant="interface"
            data-testid="move-category"
            :disabled="saving || chosen === null"
          >
            Przenieś
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
