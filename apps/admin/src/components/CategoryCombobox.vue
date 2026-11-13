<script setup lang="ts">
import { Check, ChevronDown, X } from "lucide-vue-next";
import { ComboboxInput } from "reka-ui";
import { computed, nextTick, ref, useId } from "vue";
import { Badge } from "@/components/ui/badge";
import {
  Combobox,
  ComboboxAnchor,
  ComboboxEmpty,
  ComboboxItem,
  ComboboxItemIndicator,
  ComboboxList,
  ComboboxViewport,
} from "@/components/ui/combobox";
import { Label } from "@/components/ui/label";
import type { CategoryTreeNode } from "@/composables/useCategoryTree";
import { cn } from "@/lib/utils";

/**
 * A category picker over the tree of `useCategoryTree` (docs/features/admin-design/spec.md, „Produkty”): one category
 * (`modelValue` is the category id or null) or several (`multiple`, `modelValue` is a list of ids). The options follow
 * the order of the tree, are indented by their level and are named by the full path („Turystyka › Biwak › Namioty”);
 * typing filters by name and path. The input is the Reka primitive rather than the search field of the ui combobox:
 * the bordered anchor of this field already carries the frame and the focus ring.
 */
const props = withDefaults(
  defineProps<{
    /** The tree from `useCategoryTree().nodes`. */
    nodes: CategoryTreeNode[];
    /** The accessible name of the field; also its visible label unless `hideLabel` is set. */
    label: string;
    hideLabel?: boolean;
    multiple?: boolean;
    modelValue?: string | string[] | null;
    placeholder?: string;
    disabled?: boolean;
    /** The `id` of the input, for an external `aria-describedby` or a label of the form; generated when omitted. */
    id?: string;
  }>(),
  { hideLabel: false, multiple: false, modelValue: null, placeholder: "Szukaj kategorii", disabled: false, id: undefined },
);

const emit = defineEmits<{ "update:modelValue": [value: string | string[] | null] }>();

interface CategoryOption {
  id: string;
  name: string;
  path: string;
  depth: number;
}

const PATH_SEPARATOR = " › ";

const options = computed<CategoryOption[]>(() => {
  const flat: CategoryOption[] = [];
  const walk = (level: CategoryTreeNode[], parents: string[]) => {
    for (const node of level) {
      const names = [...parents, node.label];
      flat.push({ id: node.key, name: node.label, path: names.join(PATH_SEPARATOR), depth: parents.length });
      walk(node.children, names);
    }
  };
  walk(props.nodes, []);
  return flat;
});

const searchTerm = ref("");
const fold = (text: string) => text.normalize("NFC").trim().toLocaleLowerCase("pl");

// The path contains the name, so matching the path covers both.
const visibleOptions = computed(() => {
  const term = fold(searchTerm.value);
  return term === "" ? options.value : options.value.filter((option) => fold(option.path).includes(term));
});

const nameById = computed(() => new Map(options.value.map((option) => [option.id, option.name])));

const inputId = props.id ?? useId();

const selectedIds = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []));

// Reka keeps the typed text to itself, so the field follows the input and forgets the text when it is used up.
function onInput(event: Event) {
  searchTerm.value = (event.target as HTMLInputElement).value;
}

function onOpenChange() {
  searchTerm.value = "";
}

function onUpdate(value: unknown) {
  searchTerm.value = "";
  emit("update:modelValue", value as string | string[] | null);
}

const root = ref<HTMLElement | null>(null);

// The removed chip takes the focus with it: it goes to the removal button of the next chip, the previous one when
// the last chip was removed, and to the input when none is left.
async function remove(id: string) {
  const index = selectedIds.value.indexOf(id);
  const remaining = selectedIds.value.filter((selected) => selected !== id);
  emit("update:modelValue", remaining);
  const neighbour = remaining[index] ?? remaining[index - 1];
  await nextTick();
  const button = neighbour === undefined ? null : [...(root.value?.querySelectorAll<HTMLElement>("[data-chip-remove]") ?? [])].find((element) => element.dataset.chipRemove === neighbour);
  (button ?? root.value?.querySelector<HTMLElement>(`[id='${inputId}']`))?.focus();
}
</script>

<template>
  <div
    ref="root"
    class="flex flex-col gap-1.5"
  >
    <Label
      :for="inputId"
      :class="hideLabel ? 'sr-only' : undefined"
    >
      {{ label }}
    </Label>
    <Combobox
      :model-value="modelValue"
      :multiple="multiple"
      :disabled="disabled"
      :open-on-click="true"
      :ignore-filter="true"
      @update:model-value="onUpdate"
      @update:open="onOpenChange"
    >
      <ComboboxAnchor
        :class="
          cn(
            'flex min-h-10 flex-wrap items-center gap-1.5 rounded-md border border-input-border bg-card px-3 py-1 has-[input:focus-visible]:outline-2 has-[input:focus-visible]:outline-offset-2 has-[input:focus-visible]:outline-ring',
            disabled && 'opacity-50',
          )
        "
      >
        <template v-if="multiple">
          <Badge
            v-for="selectedId in selectedIds"
            :key="selectedId"
            variant="secondary"
            class="gap-1 pr-0.5"
          >
            {{ nameById.get(selectedId) ?? selectedId }}
            <button
              type="button"
              class="inline-flex size-6 items-center justify-center rounded-sm hover:bg-separator disabled:pointer-events-none"
              :aria-label="`Usuń: ${nameById.get(selectedId) ?? selectedId}`"
              :data-chip-remove="selectedId"
              :disabled="disabled"
              @click="remove(selectedId)"
            >
              <X
                class="size-3"
                aria-hidden="true"
              />
            </button>
          </Badge>
        </template>
        <ComboboxInput
          :id="inputId"
          :placeholder="placeholder"
          :display-value="(value: unknown) => (multiple ? '' : (nameById.get(String(value)) ?? ''))"
          class="min-w-24 flex-1 bg-transparent py-1 text-sm text-foreground placeholder:text-muted-foreground focus-visible:outline-none disabled:cursor-not-allowed"
          autocomplete="off"
          @input="onInput"
        />
        <ChevronDown
          class="size-4 shrink-0 text-muted-foreground"
          aria-hidden="true"
        />
      </ComboboxAnchor>
      <ComboboxList class="w-(--reka-combobox-trigger-width)">
        <ComboboxViewport class="max-h-72 overflow-y-auto p-1">
          <ComboboxEmpty class="text-muted-foreground">
            Brak pasujących kategorii.
          </ComboboxEmpty>
          <ComboboxItem
            v-for="option in visibleOptions"
            :key="option.id"
            :value="option.id"
            :aria-label="option.path"
            :data-depth="option.depth"
            :style="{ paddingLeft: `${0.5 + option.depth * 1.25}rem` }"
          >
            <span>{{ option.name }}</span>
            <ComboboxItemIndicator>
              <Check
                class="size-4"
                aria-hidden="true"
              />
            </ComboboxItemIndicator>
          </ComboboxItem>
        </ComboboxViewport>
      </ComboboxList>
    </Combobox>
  </div>
</template>
