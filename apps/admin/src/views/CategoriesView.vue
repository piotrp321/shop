<script setup lang="ts">
import { Plus } from "lucide-vue-next";
import { computed, nextTick, ref, watch } from "vue";
import { toast } from "vue-sonner";
import CategoryFormDialog from "@/categories/CategoryFormDialog.vue";
import CategoryMoveDialog from "@/categories/CategoryMoveDialog.vue";
import CategoryTree from "@/categories/CategoryTree.vue";
import { canManageCategories } from "@/categories/permissions";
import { type CategoryRow, comboboxNodes, findRow, type MoveCommand, moveCommand, moveToCommand, placeOf, subtreeIds } from "@/categories/tree";
import { type CategoryInput, type CategoryOutcome, useCategoryScreen } from "@/categories/useCategoryScreen";
import ConfirmDialog from "@/components/ConfirmDialog.vue";
import ListEmpty from "@/components/list/ListEmpty.vue";
import PageHeader from "@/components/PageHeader.vue";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { focusAfterAction } from "@/lib/focus";
import { useAuthStore } from "@/stores/auth";

const screen = useCategoryScreen();
const { rows, loading, loaded, error, busy } = screen;

// Only hides the actions: the API checks the permission. The roles come from /api/v1/admin/me.
const auth = useAuthStore();
const canManage = computed(() => canManageCategories(auth.staff));

const header = ref<InstanceType<typeof PageHeader> | null>(null);
const headingElement = computed<HTMLElement | null>(() => header.value?.heading ?? null);
const treeRegion = ref<HTMLElement | null>(null);

// Every node with subcategories is open when the tree first appears; after that the staff member decides.
const expanded = ref<string[]>([]);
const parentIds = (level: CategoryRow[]): string[] => level.flatMap((row) => (row.children.length > 0 ? [row.id, ...parentIds(row.children)] : []));
let opened = false;
watch(loaded, (isLoaded) => {
  if (isLoaded && !opened) {
    opened = true;
    expanded.value = parentIds(rows.value);
  }
});
const reveal = (id: string | null) => {
  if (id !== null && !expanded.value.includes(id)) {
    expanded.value = [...expanded.value, id];
  }
};

const nodes = computed(() => comboboxNodes(rows.value));

// What the open window is about; kept after it closes so that the text does not change while it fades out.
type Panel = { kind: "add"; parent: CategoryRow | null } | { kind: "edit"; row: CategoryRow } | { kind: "move"; row: CategoryRow; place: string } | { kind: "delete"; row: CategoryRow };
const panel = ref<Panel | null>(null);
const formOpen = ref(false);
const moveOpen = ref(false);
const deleteOpen = ref(false);
const deleteError = ref<string | null>(null);
const opener = ref<HTMLElement | null>(null);
const changeError = ref<string | null>(null);

const formPanel = computed(() => (panel.value?.kind === "add" || panel.value?.kind === "edit" ? panel.value : null));
const movePanel = computed(() => (panel.value?.kind === "move" ? panel.value : null));
const deletePanel = computed(() => (panel.value?.kind === "delete" ? panel.value : null));

const formTitle = computed(() => {
  const current = formPanel.value;
  if (current === null || current.kind === "edit") {
    return "Edytuj kategorię";
  }
  return current.parent === null ? "Dodaj kategorię" : "Dodaj podkategorię";
});
const formDescription = computed(() => {
  const current = formPanel.value;
  if (current?.kind === "edit") {
    return `${current.row.name}. Nowy slug zmienia adres kategorii i jej podkategorii w sklepie, a stare adresy przekierowują na nowe.`;
  }
  return current?.kind === "add" && current.parent !== null ? `Podkategoria trafi na koniec kategorii ${current.parent.name}.` : "Kategoria trafi na koniec kategorii głównych.";
});
const formInitial = computed<CategoryInput>(() => (formPanel.value?.kind === "edit" ? { name: formPanel.value.row.name, slug: formPanel.value.row.slug } : { name: "", slug: "" }));

function openAdd(parent: CategoryRow | null, trigger: HTMLElement) {
  changeError.value = null;
  opener.value = trigger;
  panel.value = { kind: "add", parent };
  formOpen.value = true;
}

function onAction(key: string, row: CategoryRow, trigger: HTMLElement) {
  changeError.value = null;
  opener.value = trigger;
  switch (key) {
    case "add":
      openAdd(row, trigger);
      break;
    case "edit":
      panel.value = { kind: "edit", row };
      formOpen.value = true;
      break;
    case "move":
      panel.value = { kind: "move", row, place: placeOf(rows.value, row.id) };
      moveOpen.value = true;
      break;
    case "delete":
      panel.value = { kind: "delete", row };
      deleteError.value = null;
      deleteOpen.value = true;
      break;
    case "up":
    case "down":
      void shift(row.id, key, trigger);
      break;
  }
}

function submitForm(input: CategoryInput): Promise<CategoryOutcome> {
  const current = formPanel.value!;
  return current.kind === "edit" ? screen.revise(current.row.id, input) : screen.create({ ...input, parentId: current.parent?.id ?? null });
}

function onFormSaved(outcome: Extract<CategoryOutcome, { status: "saved" }>) {
  toast.success(outcome.message);
  reveal(outcome.created?.parentId ?? null);
}

const NO_CHANGE: CategoryOutcome = { status: "problem", problem: { kind: "failed", message: "Kategoria już jest w tym miejscu." } };

async function submitMove(parentId: string | null): Promise<CategoryOutcome> {
  const current = movePanel.value!;
  const command = moveToCommand(rows.value, current.row.id, parentId);
  return command === null ? NO_CHANGE : screen.move(command);
}

function onMoveSaved(outcome: Extract<CategoryOutcome, { status: "saved" }>) {
  toast.success(outcome.message);
  reveal(outcome.moved?.parentId ?? null);
}

/** A change without a window (up, down, a drop): the outcome is a toast or, for a refusal, the alert above the tree. */
async function change(command: MoveCommand): Promise<boolean> {
  changeError.value = null;
  const outcome = await screen.move(command);
  if (outcome.status === "saved") {
    toast.success(outcome.message);
    reveal(outcome.moved?.parentId ?? null);
    return true;
  }
  changeError.value = outcome.problem.message;
  return false;
}

// The row moves in the DOM, which takes the focus from its button: the focus goes back to the same button, to the other
// direction when this one is gone (the edge of the siblings), to the row otherwise.
async function shift(id: string, direction: "up" | "down", trigger: HTMLElement) {
  const command = moveCommand(rows.value, id, direction);
  if (command === null || !(await change(command))) {
    return;
  }
  await nextTick();
  const row = treeRegion.value?.querySelector<HTMLElement>(`[data-category-id="${id}"]`) ?? null;
  const button = (key: string) => row?.querySelector<HTMLElement>(`[data-testid="action-${key}"]`) ?? null;
  const other = direction === "up" ? "down" : "up";
  (button(direction) ?? button(other) ?? row ?? (trigger.isConnected ? trigger : headingElement.value))?.focus();
}

async function confirmDelete() {
  const current = deletePanel.value;
  if (current === null) {
    return;
  }
  deleteError.value = null;
  const outcome = await screen.remove(current.row.id);
  if (outcome.status === "saved") {
    deleteOpen.value = false;
    toast.success(outcome.message);
  } else {
    deleteError.value = outcome.problem.message;
  }
}

async function retry(event: MouseEvent) {
  const trigger = event.currentTarget as HTMLElement;
  await screen.load();
  await nextTick();
  focusAfterAction(trigger, headingElement.value);
}

const movingIds = computed(() => (movePanel.value === null ? [] : subtreeIds(findRow(rows.value, movePanel.value.row.id)?.row ?? movePanel.value.row)));
</script>

<template>
  <div class="flex flex-col gap-6">
    <PageHeader
      ref="header"
      title="Kategorie"
      :crumbs="[{ label: 'Sklep' }, { label: 'Katalog' }, { label: 'Kategorie' }]"
    />

    <Card class="gap-0 py-0">
      <div class="flex flex-wrap items-center justify-between gap-3 border-b border-separator px-4 py-3">
        <p class="min-w-0 flex-1 basis-64 text-sm text-muted-foreground">
          Kolejność kategorii jest kolejnością w sklepie.
          <template v-if="canManage">
            Przeciągnij kategorię, żeby zmienić jej miejsce, albo użyj przycisków przy niej.
          </template>
        </p>
        <Button
          v-if="canManage && loaded"
          type="button"
          data-testid="add-category"
          @click="openAdd(null, $event.currentTarget as HTMLElement)"
        >
          <Plus aria-hidden="true" />
          Dodaj kategorię
        </Button>
      </div>

      <Alert
        v-if="error"
        data-testid="categories-error"
        variant="destructive"
        class="m-4 flex w-auto flex-wrap items-center gap-3"
      >
        <AlertDescription>{{ error }}</AlertDescription>
        <Button
          type="button"
          variant="outline"
          data-testid="retry"
          @click="retry"
        >
          Spróbuj ponownie
        </Button>
      </Alert>

      <Alert
        v-if="changeError"
        data-testid="change-error"
        variant="destructive"
        class="m-4 w-auto"
      >
        <AlertDescription>{{ changeError }}</AlertDescription>
      </Alert>

      <div
        v-if="!loaded && loading"
        data-testid="categories-loading"
        role="status"
        class="flex flex-col gap-3 p-4"
      >
        <span class="sr-only">Wczytywanie kategorii…</span>
        <Skeleton
          v-for="line in 5"
          :key="line"
          class="h-8 w-full"
          aria-hidden="true"
        />
      </div>

      <div
        v-else-if="rows.length > 0"
        ref="treeRegion"
        :aria-busy="loading ? 'true' : undefined"
      >
        <CategoryTree
          v-model:expanded="expanded"
          :rows="rows"
          :can-manage="canManage"
          @action="onAction"
          @move="change"
        />
      </div>

      <ListEmpty
        v-else-if="loaded"
        :filtered="false"
        empty-text="Nie ma jeszcze żadnych kategorii."
      >
        <template
          v-if="canManage"
          #action
        >
          <Button
            type="button"
            data-testid="empty-add-category"
            @click="openAdd(null, $event.currentTarget as HTMLElement)"
          >
            <Plus aria-hidden="true" />
            Dodaj kategorię
          </Button>
        </template>
      </ListEmpty>
    </Card>

    <CategoryFormDialog
      v-model:open="formOpen"
      :title="formTitle"
      :description="formDescription"
      :submit-label="formPanel?.kind === 'edit' ? 'Zapisz' : 'Dodaj'"
      :initial="formInitial"
      :submit="submitForm"
      :return-focus-to="opener"
      :fallback-focus="headingElement"
      @saved="onFormSaved"
    />
    <CategoryMoveDialog
      v-model:open="moveOpen"
      :name="movePanel?.row.name ?? ''"
      :place="movePanel?.place ?? ''"
      :nodes="nodes"
      :disabled-ids="movingIds"
      :submit="submitMove"
      :return-focus-to="opener"
      :fallback-focus="headingElement"
      @saved="onMoveSaved"
    />
    <ConfirmDialog
      v-model:open="deleteOpen"
      :title="`Usunąć kategorię ${deletePanel?.row.name ?? ''}?`"
      description="Kategoria zniknie z drzewa, a jej stare adresy przestaną przekierowywać. Usunąć można tylko kategorię bez podkategorii i produktów."
      confirm-label="Usuń kategorię"
      destructive
      :close-on-confirm="false"
      :busy="busy"
      :error="deleteError"
      :fallback-focus="headingElement"
      @confirm="confirmDelete"
    />
  </div>
</template>
