<script setup lang="ts">
import { ArrowDown, ArrowUp, ChevronRight, FolderInput, Pencil, Plus, Trash2 } from "lucide-vue-next";
import { TreeItem, TreeRoot } from "reka-ui";
import { computed, ref } from "vue";
import RowActions, { type RowAction } from "@/components/list/RowActions.vue";
import { type CategoryRow, dropCommand, type DropZone, formatProductCount, type MoveCommand, moveCommand, zoneForRatio } from "./tree";

/**
 * The tree of the categories (docs/features/admin-features/spec.md, „Ekran „Kategorie””) on the Reka UI `Tree`:
 * `role="tree"`, the arrows move between nodes and open and close them, a node is named by its category, slug and count
 * and has the actions of `RowActions` for the staff that may change the tree. A node has no `aria-label` of its own:
 * its name is its content (the name, the slug, the count and the names of its buttons), because a shorter name fails
 * „Label in Name” (WCAG 2.5.3, axe `label-content-name-mismatch`: the names of the buttons count as visible text). Dragging a node (native HTML5 drag and
 * drop) is a shortcut: a drop gives the same command as „Przenieś do…”, „W górę” and „W dół” (`dropCommand`) and
 * emits it as `move`, so the keyboard and the screen reader lose nothing. The rows are the tree of the API; the
 * view reloads it after every change.
 */
const props = defineProps<{ rows: CategoryRow[]; canManage: boolean }>();
const emit = defineEmits<{ action: [key: string, row: CategoryRow, trigger: HTMLElement]; move: [command: MoveCommand] }>();

const expanded = defineModel<string[]>("expanded", { required: true });

const getKey = (row: CategoryRow) => row.id;
// A leaf has no children for the tree: an empty list would make it an openable node.
const getChildren = (row: CategoryRow) => (row.children.length > 0 ? row.children : undefined);

function actionsOf(row: CategoryRow): RowAction[] {
  if (!props.canManage) {
    return [];
  }
  const up = moveCommand(props.rows, row.id, "up") !== null;
  const down = moveCommand(props.rows, row.id, "down") !== null;
  return [
    { key: "add", label: "Dodaj podkategorię", icon: Plus },
    { key: "edit", label: "Edytuj", icon: Pencil },
    { key: "move", label: "Przenieś do…", icon: FolderInput },
    ...(up ? [{ key: "up", label: "W górę", icon: ArrowUp }] : []),
    ...(down ? [{ key: "down", label: "W dół", icon: ArrowDown }] : []),
    { key: "delete", label: "Usuń", icon: Trash2 },
  ];
}


// Dragging: the id of the node in the hand and the zone of the node it is over (only where a drop would change the tree).
const dragging = ref<string | null>(null);
const over = ref<{ id: string; zone: DropZone } | null>(null);

function zoneAt(event: DragEvent, element: HTMLElement): DropZone {
  const rect = element.getBoundingClientRect();
  return zoneForRatio(rect.height === 0 ? 0.5 : (event.clientY - rect.top) / rect.height);
}

function onDragStart(event: DragEvent, row: CategoryRow) {
  if (!props.canManage) {
    return;
  }
  dragging.value = row.id;
  event.dataTransfer?.setData("text/plain", row.id);
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = "move";
  }
}

function onDragOver(event: DragEvent, row: CategoryRow) {
  if (dragging.value === null) {
    return;
  }
  const zone = zoneAt(event, event.currentTarget as HTMLElement);
  if (dropCommand(props.rows, dragging.value, row.id, zone) === null) {
    over.value = null;
    return;
  }
  event.preventDefault();
  if (event.dataTransfer) {
    event.dataTransfer.dropEffect = "move";
  }
  over.value = { id: row.id, zone };
}

function onDragLeave(event: DragEvent, row: CategoryRow) {
  const to = event.relatedTarget as Node | null;
  if (over.value?.id === row.id && !(to !== null && (event.currentTarget as HTMLElement).contains(to))) {
    over.value = null;
  }
}

function onDrop(event: DragEvent, row: CategoryRow) {
  const moved = dragging.value;
  if (moved === null) {
    return;
  }
  event.preventDefault();
  const command = dropCommand(props.rows, moved, row.id, zoneAt(event, event.currentTarget as HTMLElement));
  clearDrag();
  if (command !== null) {
    emit("move", command);
  }
}

function clearDrag() {
  dragging.value = null;
  over.value = null;
}

const dropMark = computed(() => over.value);
</script>

<template>
  <TreeRoot
    v-slot="{ flattenItems }"
    v-model:expanded="expanded"
    :items="rows"
    :get-key="getKey"
    :get-children="getChildren"
    aria-label="Drzewo kategorii"
    class="flex flex-col"
  >
    <TreeItem
      v-for="item in flattenItems"
      :key="item._id"
      v-bind="item.bind"
      :data-category-id="item.value.id"
      :data-drop="dropMark?.id === item.value.id ? dropMark.zone : undefined"
      :draggable="canManage ? 'true' : undefined"
      :style="{ '--level': String(item.level - 1) }"
      data-testid="category-row"
      class="group flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-separator py-2 pr-4 pl-[calc(1rem+var(--level)*0.75rem)] outline-ring last:border-b-0 hover:bg-accent focus-visible:outline-2 focus-visible:-outline-offset-2 data-[drop=after]:shadow-[inset_0_-2px_0_0_var(--color-interface)] data-[drop=before]:shadow-[inset_0_2px_0_0_var(--color-interface)] data-[drop=inside]:bg-interface-soft sm:pl-[calc(1rem+var(--level)*1.5rem)]"
      @dragstart="onDragStart($event, item.value)"
      @dragover="onDragOver($event, item.value)"
      @dragleave="onDragLeave($event, item.value)"
      @drop="onDrop($event, item.value)"
      @dragend="clearDrag"
    >
      <ChevronRight
        v-if="item.hasChildren"
        class="size-4 shrink-0 text-muted-foreground transition-transform group-aria-expanded:rotate-90"
        aria-hidden="true"
      />
      <span
        v-else
        class="size-4 shrink-0"
        aria-hidden="true"
      />
      <span class="flex min-w-0 flex-1 basis-40 flex-wrap items-baseline gap-x-3">
        <span
          data-testid="category-name"
          class="font-bold break-words text-heading"
        >{{ item.value.name }}</span>
        <span
          data-testid="category-slug"
          class="text-sm break-all text-muted-foreground"
        >{{ item.value.slug }}</span>
      </span>
      <span
        data-testid="category-count"
        class="text-sm text-muted-foreground tabular-nums"
      >Produkty: {{ formatProductCount(item.value.productCount) }}</span>
      <!-- A click or a key in the actions is theirs: it must not open or close the node. -->
      <div
        v-if="canManage"
        @click.stop
        @keydown.stop
      >
        <RowActions
          :name="item.value.name"
          :actions="actionsOf(item.value)"
          wrap
          @select="(key, trigger) => emit('action', key, item.value, trigger)"
        />
      </div>
    </TreeItem>
  </TreeRoot>
</template>
