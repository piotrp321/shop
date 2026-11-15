<script setup lang="ts">
import { Search } from "lucide-vue-next";
import { computed, ref, useId } from "vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import type { SortOption } from "@/composables/listSort";

/**
 * The toolbar of a list (docs/features/admin-design/spec.md, „Standard listy”): the search (when the API has one, with a
 * hint under it for a text that is too short; `submit` is Enter in it, for a list that searches on Enter), the filters of the list (slot `filters`), „Wyczyść filtry” while a filter
 * or the search is set, the order of the list (`Select` „Sortuj”, when the list has `sortOptions`; an option that the filters
 * in use do not allow is disabled with its reason, which is also in a status text under the field), the number of rows per
 * page (10, 25 or 50) and the main action of the list on the right (slot `actions`).
 */
const props = withDefaults(
  defineProps<{
    /** The text of the search field; undefined for a list that cannot be searched. */
    search?: string;
    searchLabel?: string;
    searchPlaceholder?: string;
    searchMax?: number;
    searchHint?: string | null;
    /** True while a filter or the search is set: „Wyczyść filtry” shows. */
    filtered: boolean;
    perPage: number;
    perPageOptions?: number[];
    /** The orders of the list; undefined for a list that cannot be sorted. */
    sortOptions?: readonly SortOption[];
    /** The key of the order in use; null when the list is in an order of its own (no option applies): `sortPlaceholder` shows. */
    sort?: string | null;
    sortPlaceholder?: string;
  }>(),
  {
    search: undefined,
    searchLabel: "Szukaj",
    searchPlaceholder: undefined,
    searchMax: undefined,
    searchHint: null,
    perPageOptions: () => [10, 25, 50],
    sortOptions: undefined,
    sort: null,
    sortPlaceholder: undefined,
  },
);

defineEmits<{ "update:search": [value: string]; "update:perPage": [value: number]; "update:sort": [value: string]; clear: []; submit: [] }>();

const ids = { hint: useId(), perPage: useId(), sort: useId(), sortHint: useId() };
const sortLabel = computed(() => props.sortOptions?.find((option) => option.value === props.sort)?.label ?? props.sortPlaceholder ?? "");
// What the disabled options say, once each: a visible text (the options themselves are dimmed as disabled).
const sortHint = computed(() => [...new Set(props.sortOptions?.map((option) => option.disabledReason).filter((reason) => reason !== undefined))].join(" "));
const searchField = ref<InstanceType<typeof Input> | null>(null);

defineExpose({
  focusSearch: () => (searchField.value?.$el as HTMLElement | undefined)?.focus(),
});
</script>

<template>
  <div class="flex flex-wrap items-start gap-x-4 gap-y-3 border-b border-dashed border-separator p-4">
    <div
      v-if="search !== undefined"
      class="flex w-full max-w-sm min-w-0 flex-col gap-1 sm:w-72"
    >
      <div class="relative">
        <Search
          class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
          aria-hidden="true"
        />
        <Input
          ref="searchField"
          :model-value="search"
          type="search"
          data-testid="search"
          class="pl-9"
          :maxlength="searchMax"
          :placeholder="searchPlaceholder"
          :aria-label="searchLabel"
          :aria-describedby="ids.hint"
          @update:model-value="$emit('update:search', String($event ?? ''))"
          @keydown.enter.prevent="$emit('submit')"
        />
      </div>
      <p
        :id="ids.hint"
        role="status"
        data-testid="search-hint"
        class="text-xs text-muted-foreground empty:hidden"
      >
        {{ searchHint }}
      </p>
    </div>

    <slot name="filters" />

    <Button
      v-if="filtered"
      type="button"
      variant="outline"
      data-testid="clear-filters"
      @click="$emit('clear')"
    >
      Wyczyść filtry
    </Button>

    <div class="ml-auto flex flex-wrap items-start gap-x-4 gap-y-3">
      <div
        v-if="sortOptions !== undefined"
        class="flex flex-col gap-1"
      >
        <div class="flex items-center gap-2">
          <Label
            :for="ids.sort"
            class="text-sm whitespace-nowrap text-muted-foreground"
          >
            Sortuj
          </Label>
          <Select
            :model-value="sort ?? undefined"
            @update:model-value="$emit('update:sort', String($event))"
          >
            <SelectTrigger
              :id="ids.sort"
              data-testid="sort"
              class="w-52"
              :aria-describedby="ids.sortHint"
            >
              <SelectValue>{{ sortLabel }}</SelectValue>
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="option in sortOptions"
                :key="option.value"
                :value="option.value"
                :disabled="option.disabledReason !== undefined"
                data-testid="sort-option"
              >
                <span class="flex flex-col">
                  <span>{{ option.label }}</span>
                  <span
                    v-if="option.disabledReason !== undefined"
                    class="text-xs text-muted-foreground"
                  >{{ option.disabledReason }}</span>
                </span>
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <p
          :id="ids.sortHint"
          role="status"
          data-testid="sort-hint"
          class="max-w-72 text-xs text-muted-foreground empty:hidden"
        >
          {{ sortHint }}
        </p>
      </div>
      <div class="flex items-center gap-2">
        <Label
          :for="ids.perPage"
          class="text-sm whitespace-nowrap text-muted-foreground"
        >
          Wierszy na stronie
        </Label>
        <Select
          :model-value="String(perPage)"
          @update:model-value="$emit('update:perPage', Number($event))"
        >
          <SelectTrigger
            :id="ids.perPage"
            data-testid="per-page"
            class="w-20"
          >
            <SelectValue>{{ perPage }}</SelectValue>
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="option in perPageOptions"
              :key="option"
              :value="String(option)"
            >
              {{ option }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>
      <slot name="actions" />
    </div>
  </div>
</template>
