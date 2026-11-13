<script setup lang="ts">
import { Search } from "lucide-vue-next";
import { ref, useId } from "vue";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

/**
 * The toolbar of a list (docs/features/admin-design/spec.md, „Standard listy”): the search (when the API has one, with a
 * hint under it for a text that is too short), the filters of the list (slot `filters`), „Wyczyść filtry” while a filter
 * or the search is set, the number of rows per page (10, 25 or 50) and the main action of the list on the right (slot
 * `actions`).
 */
withDefaults(
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
  }>(),
  { search: undefined, searchLabel: "Szukaj", searchPlaceholder: undefined, searchMax: undefined, searchHint: null, perPageOptions: () => [10, 25, 50] },
);

defineEmits<{ "update:search": [value: string]; "update:perPage": [value: number]; clear: [] }>();

const ids = { hint: useId(), perPage: useId() };
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
